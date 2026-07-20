<?php

declare(strict_types=1);

namespace NexWaypoint\Core;

/**
 * Minimal, dependency-free .env loader.
 *
 * Deliberately hand-rolled instead of vlucas/phpdotenv: NexWAYPOINT targets
 * shared hosting (DreamHost) and offline/on-the-road maintenance, so the
 * runtime has zero Composer dependencies. Supports KEY=VALUE lines,
 * '#' comments, blank lines, and single/double-quoted values.
 *
 * Admins can update a small allowlisted set of keys via Settings → Integrations
 * (see Env::update). Database credentials and session secrets are not writable
 * from the web UI.
 */
final class Env
{
    private static bool $loaded = false;

    private static ?string $path = null;

    /**
     * @var array<string, string>
     */
    private static array $values = [];

    /**
     * Keys the admin Integrations UI may change. Keep this tight.
     *
     * @var list<string>
     */
    public const INTEGRATION_KEYS = [
        'MAIL_SOURCE',
        'IMAP_HOST',
        'IMAP_PORT',
        'IMAP_ENCRYPTION',
        'IMAP_USERNAME',
        'IMAP_PASSWORD',
        'IMAP_INBOX_FOLDER',
        'IMAP_PROCESSED_FOLDER',
        'IMAP_FAILED_FOLDER',
        'MAIL_DELETE_ON_SUCCESS',
        'MAIL_MIN_PARSE_CONFIDENCE',
        'FLIGHTAWARE_API_KEY',
        'FLIGHTAWARE_BASE_URL',
        'FLIGHTAWARE_RATE_LIMIT_PER_MINUTE',
        'FLIGHTAWARE_CACHE_MINUTES',
        'FLIGHTAWARE_MONTHLY_BUDGET_USD',
    ];

    /**
     * @var list<string>
     */
    public const SECRET_KEYS = [
        'IMAP_PASSWORD',
        'FLIGHTAWARE_API_KEY',
    ];

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                "Env file not found or unreadable: {$path}. Copy .env.example to .env and configure it."
            );
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Failed to read env file: {$path}");
        }

        foreach ($lines as $line) {
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                continue;
            }
            [$key, $value] = $parsed;
            self::$values[$key] = $value;
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        self::$path = $path;
        self::$loaded = true;
    }

    public static function path(): ?string
    {
        return self::$path;
    }

    /** @internal Tests only */
    public static function resetForTesting(): void
    {
        self::$loaded = false;
        self::$path = null;
        self::$values = [];
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $fromEnv = getenv($key);
        return $fromEnv !== false ? $fromEnv : $default;
    }

    public static function getRequired(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Required environment variable '{$key}' is not set.");
        }
        return $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function isSecretSet(string $key): bool
    {
        $value = self::get($key);
        return $value !== null && $value !== '' && $value !== 'change_me';
    }

    /**
     * Update allowlisted keys in the loaded .env file (preserves comments/order).
     * Secret keys with an empty string are left unchanged (UI "leave blank to keep").
     *
     * @param array<string, string> $updates
     * @param list<string> $allowedKeys
     * @return list<string> Keys that were written
     */
    public static function update(array $updates, array $allowedKeys): array
    {
        if (self::$path === null || !self::$loaded) {
            throw new \RuntimeException('Env file is not loaded; cannot update.');
        }
        if (!is_writable(self::$path)) {
            throw new \RuntimeException(
                'Env file is not writable by the web user. Fix ownership/permissions on .env, or edit it over SSH.'
            );
        }

        $allowed = array_fill_keys($allowedKeys, true);
        $toWrite = [];
        foreach ($updates as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new \InvalidArgumentException("Env key '{$key}' is not writable from the UI.");
            }
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException("Invalid env key '{$key}'.");
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException("Env value for '{$key}' must be a string.");
            }
            if (in_array($key, self::SECRET_KEYS, true) && $value === '') {
                continue;
            }
            $toWrite[$key] = $value;
        }

        if ($toWrite === []) {
            return [];
        }

        $raw = file(self::$path, FILE_IGNORE_NEW_LINES);
        if ($raw === false) {
            throw new \RuntimeException('Failed to read env file for update.');
        }

        $found = array_fill_keys(array_keys($toWrite), false);
        foreach ($raw as $index => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eqPos));
            if (!array_key_exists($key, $toWrite)) {
                continue;
            }
            if ($found[$key]) {
                unset($raw[$index]);
                continue;
            }
            $raw[$index] = $key . '=' . self::encodeValue($toWrite[$key]);
            $found[$key] = true;
        }

        foreach ($toWrite as $key => $value) {
            if (!$found[$key]) {
                $raw[] = $key . '=' . self::encodeValue($value);
            }
        }

        $contents = implode("\n", $raw);
        if ($contents !== '' && !str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        if (file_put_contents(self::$path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write env file.');
        }

        foreach ($toWrite as $key => $value) {
            self::$values[$key] = $value;
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        return array_keys($toWrite);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            return null;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return [$key, $value];
    }

    private static function encodeValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
