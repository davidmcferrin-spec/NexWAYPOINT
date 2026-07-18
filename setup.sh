#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
SKIP_PACKAGES=false
SKIP_USER=false

usage() {
    cat <<'EOF'
Install NexWAYPOINT on a Debian/Ubuntu or DreamHost VPS.

Usage: ./setup.sh [options]

Options:
  --skip-packages  Do not attempt to install system packages
  --skip-user      Do not offer to create a local login
  --help           Show this help

The script is safe to rerun: it preserves an existing .env file, skips an
installed database schema, and does not create users without confirmation.
EOF
}

ask_yes_no() {
    local prompt="$1"
    local default="${2:-n}"
    local answer
    local hint="[y/N]"

    if [[ "${default}" == "y" ]]; then
        hint="[Y/n]"
    fi

    read -r -p "${prompt} ${hint} " answer
    answer="${answer:-${default}}"
    [[ "${answer,,}" == "y" || "${answer,,}" == "yes" ]]
}

prompt_value() {
    local prompt="$1"
    local default="${2:-}"
    local value

    if [[ -n "${default}" ]]; then
        read -r -p "${prompt} [${default}]: " value
        printf '%s' "${value:-${default}}"
    else
        read -r -p "${prompt}: " value
        while [[ -z "${value}" ]]; do
            read -r -p "${prompt} (required): " value
        done
        printf '%s' "${value}"
    fi
}

prompt_secret() {
    local prompt="$1"
    local value

    read -r -s -p "${prompt}: " value
    printf '\n' >&2
    while [[ -z "${value}" ]]; do
        read -r -s -p "${prompt} (required): " value
        printf '\n' >&2
    done
    printf '%s' "${value}"
}

set_env_value() {
    local key="$1"
    local value="$2"

    php -r '
        [$path, $key, $value] = array_slice($argv, 1);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            fwrite(STDERR, "Unable to read {$path}.\n");
            exit(1);
        }
        $replacement = "{$key}={$value}";
        $found = false;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, "{$key}=")) {
                if (!$found) {
                    $lines[$index] = $replacement;
                    $found = true;
                } else {
                    unset($lines[$index]);
                }
            }
        }
        if (!$found) {
            $lines[] = $replacement;
        }
        if (file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            fwrite(STDERR, "Unable to write {$path}.\n");
            exit(1);
        }
    ' "${ENV_FILE}" "${key}" "${value}"
}

env_value() {
    local key="$1"
    awk -v target="${key}=" '
        index($0, target) == 1 {
            value = substr($0, length(target) + 1)
        }
        END {
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
            gsub(/^["'"'"']|["'"'"']$/, "", value)
            print value
        }
    ' "${ENV_FILE}"
}

install_packages() {
    if [[ "${SKIP_PACKAGES}" == true ]]; then
        return
    fi
    if ! command -v apt-get >/dev/null 2>&1; then
        echo "No apt-get found; assuming this managed VPS provides PHP." >&2
        return
    fi
    if ! ask_yes_no "Install/update PHP, database clients, and Composer?" "y"; then
        return
    fi

    local elevate=()
    if [[ "${EUID}" -ne 0 ]]; then
        if ! command -v sudo >/dev/null 2>&1; then
            echo "Cannot install packages without root or sudo; continuing with installed software." >&2
            return
        fi
        elevate=(sudo)
    fi

    "${elevate[@]}" apt-get update
    "${elevate[@]}" apt-get install -y \
        php-cli php-mysql php-sqlite3 php-imap php-curl php-mbstring php-xml \
        mariadb-client composer unzip
}

verify_php() {
    if ! command -v php >/dev/null 2>&1; then
        echo "PHP was not found. Install PHP 8.1+ or rerun without --skip-packages." >&2
        exit 1
    fi
    if ! php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
        echo "NexWAYPOINT requires PHP 8.1 or newer; found $(php -r 'echo PHP_VERSION;')." >&2
        exit 1
    fi

    local extension
    local missing=()
    for extension in pdo imap curl mbstring json; do
        if ! php -r "exit(extension_loaded('${extension}') ? 0 : 1);"; then
            missing+=("${extension}")
        fi
    done
    if (( ${#missing[@]} > 0 )); then
        echo "Missing required PHP extensions: ${missing[*]}" >&2
        exit 1
    fi
}

configure_new_env() {
    local driver
    local timezone

    cp "${ROOT_DIR}/.env.example" "${ENV_FILE}"
    chmod 600 "${ENV_FILE}"
    set_env_value "LOG_FILE" "${ROOT_DIR}/storage/logs/app.log"
    set_env_value "DB_SQLITE_PATH" "${ROOT_DIR}/storage/nexwaypoint.sqlite"
    set_env_value "FLIGHTAWARE_RATELIMIT_STATE_FILE" "${ROOT_DIR}/storage/cache/flightaware_ratelimit.json"
    set_env_value "HOTEL_PHOTO_UPLOAD_DIR" "${ROOT_DIR}/storage/uploads/hotel_photos"
    set_env_value "SESSION_SECRET" "$(php -r 'echo bin2hex(random_bytes(32));')"

    timezone="$(prompt_value "Application timezone" "America/Chicago")"
    set_env_value "APP_TIMEZONE" "${timezone}"

    driver="$(prompt_value "Database driver (mysql or sqlite)" "mysql")"
    driver="${driver,,}"
    if [[ "${driver}" != "mysql" && "${driver}" != "sqlite" ]]; then
        echo "Database driver must be mysql or sqlite." >&2
        exit 2
    fi
    set_env_value "DB_DRIVER" "${driver}"

    if [[ "${driver}" == "mysql" ]]; then
        echo "Create the empty database and user in the DreamHost panel before continuing."
        set_env_value "DB_HOST" "$(prompt_value "MySQL hostname")"
        set_env_value "DB_PORT" "$(prompt_value "MySQL port" "3306")"
        set_env_value "DB_NAME" "$(prompt_value "MySQL database name" "nexwaypoint")"
        set_env_value "DB_USER" "$(prompt_value "MySQL username" "nexwaypoint_app")"
        set_env_value "DB_PASSWORD" "$(prompt_secret "MySQL password")"
    else
        set_env_value "DB_SQLITE_PATH" "$(prompt_value "SQLite database path" "${ROOT_DIR}/storage/nexwaypoint.sqlite")"
    fi

    if ask_yes_no "Configure DreamHost IMAP now?" "n"; then
        set_env_value "IMAP_HOST" "$(prompt_value "IMAP hostname" "imap.dreamhost.com")"
        set_env_value "IMAP_PORT" "$(prompt_value "IMAP port" "993")"
        set_env_value "IMAP_ENCRYPTION" "$(prompt_value "IMAP encryption" "ssl")"
        set_env_value "IMAP_USERNAME" "$(prompt_value "IMAP username")"
        set_env_value "IMAP_PASSWORD" "$(prompt_secret "IMAP password")"
    fi

    if ask_yes_no "Configure FlightAware AeroAPI now?" "n"; then
        set_env_value "FLIGHTAWARE_API_KEY" "$(prompt_secret "FlightAware API key")"
    fi

    chmod 600 "${ENV_FILE}"
    echo "Created ${ENV_FILE}."
}

verify_database_extension() {
    local driver
    local extension

    driver="$(env_value DB_DRIVER)"
    case "${driver}" in
        mysql) extension="pdo_mysql" ;;
        sqlite) extension="pdo_sqlite" ;;
        *)
            echo "DB_DRIVER in .env must be mysql or sqlite." >&2
            exit 2
            ;;
    esac

    if ! php -r "exit(extension_loaded('${extension}') ? 0 : 1);"; then
        echo "DB_DRIVER=${driver} requires the PHP ${extension} extension." >&2
        exit 1
    fi

    if [[ "${driver}" == "mysql" ]]; then
        local key
        local value
        for key in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
            value="$(env_value "${key}")"
            if [[ -z "${value}" || "${value}" == "change_me" || "${value}" == "mysql.yourdomain.com" ]]; then
                echo "${key} is not configured in ${ENV_FILE}." >&2
                exit 2
            fi
        done
    else
        local sqlite_path
        sqlite_path="$(env_value DB_SQLITE_PATH)"
        if [[ -z "${sqlite_path}" ]]; then
            echo "DB_SQLITE_PATH is not configured in ${ENV_FILE}." >&2
            exit 2
        fi
        mkdir -p "$(dirname -- "${sqlite_path}")"
    fi
}

install_cron_jobs() {
    local imap_password
    local flightaware_key
    local install_mail=false
    local install_flights=false

    imap_password="$(env_value IMAP_PASSWORD)"
    flightaware_key="$(env_value FLIGHTAWARE_API_KEY)"
    if [[ -n "${imap_password}" && "${imap_password}" != "change_me" ]]; then
        install_mail=true
    fi
    if [[ -n "${flightaware_key}" && "${flightaware_key}" != "change_me" ]]; then
        install_flights=true
    fi
    if [[ "${install_mail}" == false && "${install_flights}" == false ]]; then
        echo "Skipping cron: configure IMAP or FlightAware credentials in .env first."
        return
    fi

    if ! command -v crontab >/dev/null 2>&1; then
        echo "crontab is unavailable; add the jobs shown in README.md through the DreamHost panel." >&2
        return
    fi
    if ! ask_yes_no "Install 10-minute mail and flight cron jobs for this user?" "y"; then
        return
    fi

    local current
    local updated
    local php_path
    current="$(mktemp)"
    updated="$(mktemp)"
    php_path="$(command -v php)"

    crontab -l > "${current}" 2>/dev/null || true
    awk '
        $0 == "# BEGIN NEXWAYPOINT" { skipping = 1; next }
        $0 == "# END NEXWAYPOINT" { skipping = 0; next }
        !skipping { print }
    ' "${current}" > "${updated}"
    {
        echo "# BEGIN NEXWAYPOINT"
        if [[ "${install_mail}" == true ]]; then
            echo "*/10 * * * * ${php_path} '${ROOT_DIR}/cron/poll_mail.php' >> '${ROOT_DIR}/storage/logs/cron.log' 2>&1"
        fi
        if [[ "${install_flights}" == true ]]; then
            echo "*/10 * * * * ${php_path} '${ROOT_DIR}/cron/enrich_flights.php' >> '${ROOT_DIR}/storage/logs/cron.log' 2>&1"
        fi
        echo "# END NEXWAYPOINT"
    } >> "${updated}"
    crontab "${updated}"
    rm -f "${current}" "${updated}"
    echo "Cron jobs installed."
}

while (( $# > 0 )); do
    case "$1" in
        --skip-packages) SKIP_PACKAGES=true ;;
        --skip-user) SKIP_USER=true ;;
        --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
    shift
done

echo "NexWAYPOINT setup"
echo "Project: ${ROOT_DIR}"

install_packages
verify_php

mkdir -p \
    "${ROOT_DIR}/storage/logs" \
    "${ROOT_DIR}/storage/cache" \
    "${ROOT_DIR}/storage/uploads/hotel_photos"
chmod 775 \
    "${ROOT_DIR}/storage" \
    "${ROOT_DIR}/storage/logs" \
    "${ROOT_DIR}/storage/cache" \
    "${ROOT_DIR}/storage/uploads" \
    "${ROOT_DIR}/storage/uploads/hotel_photos"

if [[ ! -f "${ENV_FILE}" ]]; then
    configure_new_env
else
    echo "Keeping existing ${ENV_FILE}."
    chmod 600 "${ENV_FILE}"
fi

verify_database_extension
php "${ROOT_DIR}/scripts/init_database.php"

if [[ "${SKIP_USER}" == false ]] && ask_yes_no "Create a local login now?" "y"; then
    php "${ROOT_DIR}/scripts/create_user.php"
fi

install_cron_jobs

if command -v composer >/dev/null 2>&1 && ask_yes_no "Install development/test dependencies?" "n"; then
    composer install --working-dir="${ROOT_DIR}" --no-interaction --prefer-dist
fi

cat <<EOF

Setup complete.

Next:
  1. Point the domain's document root at ${ROOT_DIR}/public
  2. Require HTTPS before signing in
  3. Open https://your-domain.example/login.php

Application logs: ${ROOT_DIR}/storage/logs/app.log
EOF
