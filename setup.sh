#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
BACKUP_ROOT="${ROOT_DIR}/storage/backups"
SKIP_PACKAGES=true
SKIP_USER=false
WITH_DEV=false
COMMAND="install"
UPDATE_REF=""
RESTORE_TARGET="latest"
RESET_USERNAME="admin"
NO_BACKUP=false
RESTORE_CODE=false
FORCE_UPDATE=false

# Production layout on DreamHost:
#   code: /home/dh_w9tij7/NexWAYPOINT
#   web:  /home/dh_w9tij7/nexwaypoint.area51consulting.com
# setup.sh deploy publishes public/ into the DreamHost domain folder via
# absolute symlinks (keeps the domain directory itself; does not replace it).
DEFAULT_SITE_HOST="nexwaypoint.area51consulting.com"
DEFAULT_SITE_URL="https://${DEFAULT_SITE_HOST}"
DEFAULT_WEB_ROOT="/home/dh_w9tij7/${DEFAULT_SITE_HOST}"
SITE_URL="${DEFAULT_SITE_URL}"
WEB_ROOT="${DEFAULT_WEB_ROOT}"
SKIP_DEPLOY=false

usage() {
    cat <<'EOF'
Install and maintain NexWAYPOINT on DreamHost (no sudo) or a Debian/Ubuntu host.

Usage:
  ./setup.sh [install options]           First-time / re-run install
  ./setup.sh deploy                      Publish public/ into the DreamHost web dir
  ./setup.sh backup                      Snapshot .env, storage, and DB dump
  ./setup.sh update [--ref REF]          Backup, git-pull, then redeploy web
  ./setup.sh restore [ID|latest] [--code]
  ./setup.sh reset-password [--username NAME]
  ./setup.sh list-backups                Show available backup IDs

Install options:
  --skip-user          Do not auto-seed the admin account
  --skip-deploy        Do not publish public/ into the DreamHost web directory
  --web-root PATH      DreamHost domain web directory
                       (default: /home/dh_w9tij7/nexwaypoint.area51consulting.com)
  --install-packages   Attempt apt package installs (requires root/sudo)
  --with-dev           Offer to run Composer for PHPUnit only

Update / restore options:
  --ref REF            Branch, tag, or commit to update to (default: current branch)
  --username NAME      Username for reset-password (default: admin)
  --no-backup          Skip the automatic pre-update / pre-restore backup
  --force              Allow update with a dirty git working tree
  --code               On restore, also check out the git SHA recorded in the backup

Legacy flag forms also work: --backup, --update, --restore [ID], --list-backups

DreamHost note: no sudo, apt, or Composer needed at runtime. Use the panel for
PHP and MySQL. Leave the domain Web Directory at the default domain folder;
setup.sh publishes web files into it with symlinks to NexWAYPOINT/public so
PHP bootstrap paths keep working. The domain folder itself is never replaced.

Backups are stored under storage/backups/<timestamp>/ (outside the web root).
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
        echo "Skipping system package installs (DreamHost has no sudo/apt for this account)."
        echo "Enable PHP 8.1+ with pdo_mysql, imap, curl, and mbstring in the DreamHost panel if needed."
        return
    fi
    if ! command -v apt-get >/dev/null 2>&1; then
        echo "No apt-get found; using the PHP already on PATH." >&2
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

    if ! ask_yes_no "Install/update PHP, database clients, and Composer via apt?" "n"; then
        return
    fi

    "${elevate[@]}" apt-get update
    "${elevate[@]}" apt-get install -y \
        php-cli php-mysql php-sqlite3 php-imap php-curl php-mbstring php-xml \
        mariadb-client composer unzip
}

verify_php() {
    if ! command -v php >/dev/null 2>&1; then
        echo "PHP was not found on PATH." >&2
        echo "On DreamHost: Domains → Manage Domains / PHP settings, select PHP 8.1+, then open a new SSH session." >&2
        exit 1
    fi
    if ! php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
        echo "NexWAYPOINT requires PHP 8.1 or newer; found $(php -r 'echo PHP_VERSION;')." >&2
        echo "Raise the domain PHP version in the DreamHost panel (no sudo required)." >&2
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
        echo "On DreamHost these come with the selected PHP build; switch PHP version in the panel or open a support ticket." >&2
        exit 1
    fi

    echo "Using $(php -r 'echo PHP_VERSION;') ($(command -v php))"
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
    set_env_value "ADMIN_USERNAME" "admin"
    set_env_value "ADMIN_EMAIL" "admin@${DEFAULT_SITE_HOST}"

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

ensure_storage_dirs() {
    mkdir -p \
        "${ROOT_DIR}/storage/logs" \
        "${ROOT_DIR}/storage/cache" \
        "${ROOT_DIR}/storage/uploads/hotel_photos" \
        "${BACKUP_ROOT}"
    chmod 775 \
        "${ROOT_DIR}/storage" \
        "${ROOT_DIR}/storage/logs" \
        "${ROOT_DIR}/storage/cache" \
        "${ROOT_DIR}/storage/uploads" \
        "${ROOT_DIR}/storage/uploads/hotel_photos" \
        "${BACKUP_ROOT}"
}

deploy_web() {
    local public_dir="${ROOT_DIR}/public"
    local item
    local name
    local target
    local linked=0

    if [[ "${SKIP_DEPLOY}" == true ]]; then
        echo "Skipping web deploy (--skip-deploy)."
        return
    fi

    if [[ ! -d "${public_dir}" ]]; then
        echo "Missing public/ directory at ${public_dir}." >&2
        exit 1
    fi

    if [[ ! -d "${WEB_ROOT}" ]]; then
        echo "Creating DreamHost web directory ${WEB_ROOT}"
        mkdir -p "${WEB_ROOT}"
    fi

    if [[ -e "${WEB_ROOT}/index.html" && ! -L "${WEB_ROOT}/index.html" ]]; then
        mv -- "${WEB_ROOT}/index.html" "${WEB_ROOT}/index.html.dreamhost.bak.$(date +%Y%m%d%H%M%S)"
        echo "Moved DreamHost placeholder index.html aside."
    fi

    # Publish every top-level public/ entry into the domain folder via absolute
    # symlinks. PHP resolves __DIR__/__FILE__ through the real path, so
    # config/bootstrap.php keeps resolving under the git clone.
    shopt -s nullglob
    for item in "${public_dir}"/*; do
        name="$(basename -- "${item}")"
        target="${WEB_ROOT}/${name}"
        if [[ -e "${target}" || -L "${target}" ]]; then
            if [[ -L "${target}" ]]; then
                rm -- "${target}"
            elif [[ -d "${target}" && ! -L "${target}" ]]; then
                echo "Refusing to replace existing directory ${target}. Move it aside and rerun deploy." >&2
                exit 1
            else
                mv -- "${target}" "${target}.bak.$(date +%Y%m%d%H%M%S)"
            fi
        fi
        ln -sfn "${item}" "${target}"
        linked=$((linked + 1))
        echo "  ${name} -> ${item}"
    done
    shopt -u nullglob

    # Drop stale NexWAYPOINT symlinks that no longer exist in public/.
    shopt -s nullglob
    for item in "${WEB_ROOT}"/*; do
        [[ -L "${item}" ]] || continue
        target="$(readlink -- "${item}")"
        if [[ "${target}" == "${public_dir}/"* && ! -e "${target}" ]]; then
            rm -- "${item}"
            echo "  removed stale symlink $(basename -- "${item}")"
        fi
    done
    shopt -u nullglob

    if (( linked == 0 )); then
        echo "No files found under ${public_dir} to deploy." >&2
        exit 1
    fi

    echo "Web deployed: ${linked} entries published into ${WEB_ROOT}"
}

cmd_deploy() {
    echo "NexWAYPOINT web deploy"
    echo "Public: ${ROOT_DIR}/public"
    echo "Web:    ${WEB_ROOT}"
    deploy_web
}

require_git_repo() {
    if ! command -v git >/dev/null 2>&1; then
        echo "git is required for update/backup metadata." >&2
        exit 1
    fi
    if [[ ! -d "${ROOT_DIR}/.git" ]]; then
        echo "${ROOT_DIR} is not a git checkout. Clone the repo before using update." >&2
        exit 1
    fi
}

git_current_sha() {
    git -C "${ROOT_DIR}" rev-parse HEAD 2>/dev/null || true
}

git_current_branch() {
    git -C "${ROOT_DIR}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "DETACHED"
}

git_working_tree_dirty() {
    [[ -n "$(git -C "${ROOT_DIR}" status --porcelain 2>/dev/null || true)" ]]
}

resolve_backup_dir() {
    local target="${1:-latest}"
    local latest=""

    if [[ ! -d "${BACKUP_ROOT}" ]]; then
        echo "No backups found in ${BACKUP_ROOT}." >&2
        exit 1
    fi

    if [[ "${target}" == "latest" ]]; then
        latest="$(
            shopt -s nullglob
            for entry in "${BACKUP_ROOT}"/*; do
                [[ -d "${entry}" ]] && basename -- "${entry}"
            done | sort | tail -n 1
        )"
        if [[ -z "${latest}" ]]; then
            echo "No backups found in ${BACKUP_ROOT}." >&2
            exit 1
        fi
        target="${latest}"
    fi

    if [[ ! -d "${BACKUP_ROOT}/${target}" ]]; then
        echo "Backup not found: ${BACKUP_ROOT}/${target}" >&2
        exit 1
    fi
    printf '%s' "${BACKUP_ROOT}/${target}"
}

backup_database() {
    local destination="$1"
    local driver
    local sqlite_path

    if [[ ! -f "${ENV_FILE}" ]]; then
        echo "No .env yet; skipping database dump."
        return
    fi

    driver="$(env_value DB_DRIVER)"
    if [[ "${driver}" == "sqlite" ]]; then
        sqlite_path="$(env_value DB_SQLITE_PATH)"
        if [[ -n "${sqlite_path}" && -f "${sqlite_path}" ]]; then
            cp -- "${sqlite_path}" "${destination}/database.sqlite"
            echo "SQLite database copied."
        else
            echo "SQLite database file not found; skipping DB backup."
        fi
        return
    fi

    if [[ "${driver}" != "mysql" ]]; then
        echo "Unknown DB_DRIVER '${driver}'; skipping DB backup."
        return
    fi

    if ! command -v mysqldump >/dev/null 2>&1; then
        echo "mysqldump not available; skipping MySQL dump (code/.env/storage still backed up)."
        return
    fi

    MYSQL_PWD="$(env_value DB_PASSWORD)" \
        mysqldump \
            --host="$(env_value DB_HOST)" \
            --port="$(env_value DB_PORT)" \
            --user="$(env_value DB_USER)" \
            --single-transaction \
            --routines \
            --triggers \
            "$(env_value DB_NAME)" \
            > "${destination}/database.sql"
    echo "MySQL dump written."
}

restore_database() {
    local source_dir="$1"
    local driver
    local sqlite_path

    if [[ ! -f "${ENV_FILE}" ]]; then
        echo "No .env present after restore; skipping database restore."
        return
    fi

    driver="$(env_value DB_DRIVER)"
    if [[ "${driver}" == "sqlite" ]]; then
        if [[ -f "${source_dir}/database.sqlite" ]]; then
            sqlite_path="$(env_value DB_SQLITE_PATH)"
            if [[ -z "${sqlite_path}" ]]; then
                sqlite_path="${ROOT_DIR}/storage/nexwaypoint.sqlite"
            fi
            mkdir -p "$(dirname -- "${sqlite_path}")"
            cp -- "${source_dir}/database.sqlite" "${sqlite_path}"
            echo "SQLite database restored to ${sqlite_path}."
        else
            echo "No database.sqlite in backup; skipping DB restore."
        fi
        return
    fi

    if [[ "${driver}" != "mysql" ]]; then
        echo "Unknown DB_DRIVER '${driver}'; skipping DB restore."
        return
    fi

    if [[ ! -f "${source_dir}/database.sql" ]]; then
        echo "No database.sql in backup; skipping MySQL restore."
        return
    fi
    if ! command -v mysql >/dev/null 2>&1; then
        echo "mysql client not available; cannot restore database.sql automatically." >&2
        echo "Import manually: mysql -h HOST -u USER -p DBNAME < ${source_dir}/database.sql" >&2
        return
    fi

    MYSQL_PWD="$(env_value DB_PASSWORD)" \
        mysql \
            --host="$(env_value DB_HOST)" \
            --port="$(env_value DB_PORT)" \
            --user="$(env_value DB_USER)" \
            "$(env_value DB_NAME)" \
            < "${source_dir}/database.sql"
    echo "MySQL database restored."
}

create_backup() {
    local stamp
    local backup_dir
    local sha
    local branch

    ensure_storage_dirs
    stamp="$(date +%Y%m%d%H%M%S)"
    backup_dir="${BACKUP_ROOT}/${stamp}"
    mkdir -p "${backup_dir}"
    chmod 700 "${backup_dir}"

    sha="$(git_current_sha)"
    branch="$(git_current_branch)"

    {
        echo "created_at=${stamp}"
        echo "site=${SITE_URL}"
        echo "root=${ROOT_DIR}"
        echo "git_sha=${sha}"
        echo "git_branch=${branch}"
        echo "hostname=$(hostname 2>/dev/null || echo unknown)"
    } > "${backup_dir}/manifest.txt"

    if [[ -f "${ENV_FILE}" ]]; then
        cp -- "${ENV_FILE}" "${backup_dir}/env"
        chmod 600 "${backup_dir}/env"
    else
        echo "No .env present; backup will omit env."
    fi

    if [[ -d "${ROOT_DIR}/storage" ]]; then
        tar -C "${ROOT_DIR}" \
            --exclude='storage/backups' \
            -czf "${backup_dir}/storage.tar.gz" \
            storage
    fi

    backup_database "${backup_dir}"

    echo "Backup created: ${backup_dir}" >&2
    printf '%s' "${backup_dir}"
}

list_backups() {
    local dir
    local name
    local sha
    local branch
    local found=false

    ensure_storage_dirs
    shopt -s nullglob
    for dir in "${BACKUP_ROOT}"/*; do
        [[ -d "${dir}" ]] || continue
        if [[ "${found}" == false ]]; then
            printf '%-16s  %-10s  %s\n' "ID" "BRANCH" "SHA"
            found=true
        fi
        name="$(basename -- "${dir}")"
        sha="unknown"
        branch="unknown"
        if [[ -f "${dir}/manifest.txt" ]]; then
            sha="$(awk -F= '/^git_sha=/{print $2}' "${dir}/manifest.txt")"
            branch="$(awk -F= '/^git_branch=/{print $2}' "${dir}/manifest.txt")"
        fi
        printf '%-16s  %-10s  %s\n' "${name}" "${branch:0:10}" "${sha:0:12}"
    done
    shopt -u nullglob
    if [[ "${found}" == false ]]; then
        echo "No backups in ${BACKUP_ROOT}."
    fi
}

cmd_backup() {
    create_backup >/dev/null
}

cmd_update() {
    local before_sha
    local after_sha
    local backup_path=""
    local ref
    local pull_target

    require_git_repo
    verify_php
    ensure_storage_dirs

    before_sha="$(git_current_sha)"
    ref="${UPDATE_REF}"
    if [[ -z "${ref}" ]]; then
        ref="$(git_current_branch)"
        if [[ "${ref}" == "HEAD" || "${ref}" == "DETACHED" ]]; then
            echo "Detached HEAD; pass --ref <branch|tag|commit>." >&2
            exit 2
        fi
    fi

    if git_working_tree_dirty && [[ "${FORCE_UPDATE}" != true ]]; then
        echo "Working tree has local changes. Commit/stash them, or rerun with --force." >&2
        git -C "${ROOT_DIR}" status --short >&2
        exit 1
    fi

    if [[ "${NO_BACKUP}" != true ]]; then
        echo "Creating pre-update backup..."
        backup_path="$(create_backup)"
        echo
    fi

    echo "Fetching from origin..."
    git -C "${ROOT_DIR}" fetch --tags --prune origin

    pull_target="${ref}"
    if git -C "${ROOT_DIR}" show-ref --verify --quiet "refs/remotes/origin/${ref}"; then
        pull_target="origin/${ref}"
    fi

    echo "Updating to ${pull_target} (fast-forward only)..."
    if git -C "${ROOT_DIR}" rev-parse --verify --quiet "refs/heads/${ref}" >/dev/null; then
        git -C "${ROOT_DIR}" checkout "${ref}"
        git -C "${ROOT_DIR}" merge --ff-only "${pull_target}"
    else
        git -C "${ROOT_DIR}" checkout --detach "${pull_target}"
    fi

    after_sha="$(git_current_sha)"
    ensure_storage_dirs

    if [[ -f "${ENV_FILE}" ]]; then
        verify_database_extension
        php "${ROOT_DIR}/scripts/init_database.php"
    php "${ROOT_DIR}/scripts/migrate.php"
    else
        echo "No .env found after update; run ./setup.sh install next."
    fi

    echo
    echo "Publishing web components..."
    deploy_web

    cat <<EOF

Update complete.
  Before: ${before_sha}
  After:  ${after_sha}
  Ref:    ${ref}
  Web:    ${WEB_ROOT}
EOF
    if [[ -n "${backup_path}" ]]; then
        echo "  Backup: ${backup_path}"
        echo "  Rollback data: ./setup.sh restore $(basename -- "${backup_path}")"
        echo "  Rollback code:  git -C ${ROOT_DIR} checkout ${before_sha}"
    fi
}

cmd_restore() {
    local source_dir
    local pre_backup=""
    local recorded_sha

    verify_php
    ensure_storage_dirs
    source_dir="$(resolve_backup_dir "${RESTORE_TARGET}")"
    echo "Restoring from ${source_dir}"

    if [[ "${NO_BACKUP}" != true ]]; then
        echo "Creating safety backup of current state..."
        pre_backup="$(create_backup)"
        echo
    fi

    if [[ -f "${source_dir}/env" ]]; then
        cp -- "${source_dir}/env" "${ENV_FILE}"
        chmod 600 "${ENV_FILE}"
        echo "Restored .env"
    fi

    if [[ -f "${source_dir}/storage.tar.gz" ]]; then
        # Keep existing backups directory intact while replacing other storage.
        tar -C "${ROOT_DIR}" -xzf "${source_dir}/storage.tar.gz"
        ensure_storage_dirs
        echo "Restored storage/"
    fi

    restore_database "${source_dir}"

    if [[ "${RESTORE_CODE}" == true ]]; then
        require_git_repo
        recorded_sha="$(awk -F= '/^git_sha=/{print $2}' "${source_dir}/manifest.txt" 2>/dev/null || true)"
        if [[ -z "${recorded_sha}" ]]; then
            echo "Backup has no git_sha; cannot restore code." >&2
            exit 1
        fi
        if git_working_tree_dirty && [[ "${FORCE_UPDATE}" != true ]]; then
            echo "Working tree dirty; refuse code restore without --force." >&2
            exit 1
        fi
        git -C "${ROOT_DIR}" checkout "${recorded_sha}"
        echo "Checked out code ${recorded_sha}"
        echo
        echo "Publishing web components..."
        deploy_web
    fi

    cat <<EOF

Restore complete.
  Source: ${source_dir}
EOF
    if [[ -n "${pre_backup}" ]]; then
        echo "  Prior state saved at: ${pre_backup}"
    fi
}

cmd_reset_password() {
    verify_php
    if [[ ! -f "${ENV_FILE}" ]]; then
        echo "No ${ENV_FILE}; run ./setup.sh install first." >&2
        exit 1
    fi
    php "${ROOT_DIR}/scripts/reset_password.php" --username="${RESET_USERNAME}"
}

run_install() {
    echo "NexWAYPOINT setup"
    echo "Project: ${ROOT_DIR}"

    install_packages
    verify_php
    ensure_storage_dirs

    if [[ ! -f "${ENV_FILE}" ]]; then
        configure_new_env
    else
        echo "Keeping existing ${ENV_FILE}."
        chmod 600 "${ENV_FILE}"
    fi

    verify_database_extension
    php "${ROOT_DIR}/scripts/init_database.php"
    php "${ROOT_DIR}/scripts/migrate.php"

    if [[ "${SKIP_USER}" == false ]]; then
        echo
        echo "Seeding admin account (skipped if users already exist)..."
        php "${ROOT_DIR}/scripts/seed_admin.php"
    fi

    echo
    echo "Publishing web components into ${WEB_ROOT}..."
    deploy_web

    install_cron_jobs

    if [[ "${WITH_DEV}" == true ]]; then
        if ! command -v composer >/dev/null 2>&1; then
            echo "Composer is not on PATH. Dev tests are optional; the app itself does not need Composer." >&2
        elif ask_yes_no "Install development/test dependencies (PHPUnit)?" "n"; then
            composer install --working-dir="${ROOT_DIR}" --no-interaction --prefer-dist
        fi
    fi

    cat <<EOF

Setup complete.

Site: ${SITE_URL}
Code: ${ROOT_DIR}
Web:  ${WEB_ROOT}  (symlinks into ${ROOT_DIR}/public)

Next:
  1. Leave the DreamHost Web Directory as ${WEB_ROOT} (default domain folder)
  2. Confirm HTTPS is forced for ${DEFAULT_SITE_HOST}
  3. Open ${SITE_URL}/login.php (admin password printed above if this was a fresh install)
  4. Delete storage/admin-credentials.txt after first login
  5. Forward travel confirmations to the dedicated IMAP mailbox once configured

Maintenance:
  ./setup.sh deploy
  ./setup.sh reset-password
  ./setup.sh backup
  ./setup.sh update
  ./setup.sh restore latest
  ./setup.sh list-backups

Application logs: ${ROOT_DIR}/storage/logs/app.log
EOF
}

while (( $# > 0 )); do
    case "$1" in
        install|deploy|backup|update|restore|reset-password|list-backups)
            COMMAND="$1"
            ;;
        --backup)
            COMMAND="backup"
            ;;
        --update)
            COMMAND="update"
            ;;
        --deploy)
            COMMAND="deploy"
            ;;
        --restore)
            COMMAND="restore"
            if [[ "${2:-}" != "" && "${2:-}" != --* ]]; then
                RESTORE_TARGET="$2"
                shift
            fi
            ;;
        --list-backups)
            COMMAND="list-backups"
            ;;
        --ref)
            UPDATE_REF="${2:-}"
            if [[ -z "${UPDATE_REF}" ]]; then
                echo "--ref requires a branch, tag, or commit." >&2
                exit 2
            fi
            shift
            ;;
        --ref=*)
            UPDATE_REF="${1#*=}"
            ;;
        --web-root)
            WEB_ROOT="${2:-}"
            if [[ -z "${WEB_ROOT}" ]]; then
                echo "--web-root requires a path." >&2
                exit 2
            fi
            shift
            ;;
        --web-root=*)
            WEB_ROOT="${1#*=}"
            ;;
        --no-backup)
            NO_BACKUP=true
            ;;
        --force)
            FORCE_UPDATE=true
            ;;
        --code)
            RESTORE_CODE=true
            ;;
        --username)
            RESET_USERNAME="${2:-}"
            if [[ -z "${RESET_USERNAME}" ]]; then
                echo "--username requires a login name." >&2
                exit 2
            fi
            shift
            ;;
        --username=*)
            RESET_USERNAME="${1#*=}"
            ;;
        --skip-user) SKIP_USER=true ;;
        --skip-deploy) SKIP_DEPLOY=true ;;
        --install-packages) SKIP_PACKAGES=false ;;
        --with-dev) WITH_DEV=true ;;
        --skip-packages)
            SKIP_PACKAGES=true
            ;;
        --skip-web-root)
            SKIP_DEPLOY=true
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            if [[ "${COMMAND}" == "restore" && "$1" != --* ]]; then
                RESTORE_TARGET="$1"
            else
                echo "Unknown option: $1" >&2
                usage >&2
                exit 2
            fi
            ;;
    esac
    shift
done

case "${COMMAND}" in
    install) run_install ;;
    deploy) cmd_deploy ;;
    backup) cmd_backup ;;
    update) cmd_update ;;
    restore) cmd_restore ;;
    reset-password) cmd_reset_password ;;
    list-backups) list_backups ;;
    *)
        echo "Unknown command: ${COMMAND}" >&2
        usage >&2
        exit 2
        ;;
esac
