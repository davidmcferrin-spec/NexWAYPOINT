<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * IATA → timezone / display-label lookup.
 * Prefers the airports table; falls back to data/airports_us.php.
 */
final class AirportRepository
{
    /** @var array<string, array{timezone: string, name: ?string, city: ?string, state: ?string}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ?Database $db = null,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Resolve IANA timezone for an airport / station code.
     * Returns null when the code is not a known 3-letter airport.
     */
    public function timezoneForCode(?string $code): ?string
    {
        $meta = $this->metaFor($code);
        return $meta['timezone'] ?? null;
    }

    public function has(string $code): bool
    {
        return $this->timezoneForCode($code) !== null;
    }

    /**
     * Human label: "Washington, DC (DCA)", "Toronto (YYZ)", or raw code.
     */
    public function labelFor(?string $code): string
    {
        $iata = self::normalizeIata($code);
        if ($iata === null) {
            $raw = trim((string) $code);
            return $raw !== '' ? $raw : '?';
        }

        $meta = $this->metaFor($iata);
        if ($meta === null) {
            return $iata;
        }

        $city = trim((string) ($meta['city'] ?? ''));
        $state = trim((string) ($meta['state'] ?? ''));
        if ($city !== '' && $state !== '') {
            return "{$city}, {$state} ({$iata})";
        }
        if ($city !== '') {
            return "{$city} ({$iata})";
        }

        $name = trim((string) ($meta['name'] ?? ''));
        if ($name !== '') {
            return "{$name} ({$iata})";
        }

        return $iata;
    }

    /**
     * City (or name) without the IATA suffix — for location pins / short status.
     */
    public function cityFor(?string $code): ?string
    {
        $meta = $this->metaFor($code);
        if ($meta === null) {
            return null;
        }
        $city = trim((string) ($meta['city'] ?? ''));
        if ($city !== '') {
            $state = trim((string) ($meta['state'] ?? ''));
            return $state !== '' ? "{$city}, {$state}" : $city;
        }
        $name = trim((string) ($meta['name'] ?? ''));
        return $name !== '' ? $name : null;
    }

    public function routeLabel(?string $origin, ?string $destination, string $arrow = ' → '): string
    {
        return $this->labelFor($origin) . $arrow . $this->labelFor($destination);
    }

    /**
     * Parse a naive wall-clock datetime in the given airport's timezone.
     * Unknown codes fall back to the app default timezone.
     */
    public function instant(?string $airportCode, string $naiveDt): \DateTimeImmutable
    {
        $tzName = $this->timezoneForCode($airportCode) ?? date_default_timezone_get();
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception) {
            $tz = new \DateTimeZone(date_default_timezone_get());
        }

        try {
            return new \DateTimeImmutable($naiveDt, $tz);
        } catch (\Exception $e) {
            $this->logger?->warning('Invalid segment datetime', [
                'dt' => $naiveDt,
                'airport' => $airportCode,
                'error' => $e->getMessage(),
            ]);
            return new \DateTimeImmutable('now', $tz);
        }
    }

    /**
     * @return array{timezone: string, name: ?string, city: ?string, state: ?string}|null
     */
    private function metaFor(?string $code): ?array
    {
        $iata = self::normalizeIata($code);
        if ($iata === null) {
            return null;
        }
        $map = $this->map();
        return $map[$iata] ?? null;
    }

    /**
     * @return array<string, array{timezone: string, name: ?string, city: ?string, state: ?string}>
     */
    private function map(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $map = [];
        if ($this->db !== null && $this->db->tableExists('airports')) {
            try {
                $hasCity = $this->db->columnExists('airports', 'city');
                $cols = $hasCity
                    ? 'SELECT iata, timezone, name, city, state FROM airports'
                    : 'SELECT iata, timezone, name FROM airports';
                $rows = $this->db->fetchAll($cols);
                foreach ($rows as $row) {
                    $iata = self::normalizeIata((string) ($row['iata'] ?? ''));
                    $tz = trim((string) ($row['timezone'] ?? ''));
                    if ($iata === null || $tz === '') {
                        continue;
                    }
                    $map[$iata] = [
                        'timezone' => $tz,
                        'name' => self::nullableString($row['name'] ?? null),
                        'city' => $hasCity ? self::nullableString($row['city'] ?? null) : null,
                        'state' => $hasCity ? self::nullableString($row['state'] ?? null) : null,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Airport table read failed; using seed file', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($map === []) {
            $map = self::seedMetaMap();
        }

        $this->cache = $map;
        return $this->cache;
    }

    /**
     * @return array<string, string>
     */
    public static function seedMap(): array
    {
        $map = [];
        foreach (self::seedMetaMap() as $iata => $meta) {
            $map[$iata] = $meta['timezone'];
        }
        return $map;
    }

    /**
     * @return array<string, array{timezone: string, name: ?string, city: ?string, state: ?string}>
     */
    public static function seedMetaMap(): array
    {
        $map = [];
        foreach (self::seedRows() as $row) {
            $iata = self::normalizeIata($row['iata'] ?? null);
            $tz = trim((string) ($row['timezone'] ?? ''));
            if ($iata === null || $tz === '') {
                continue;
            }
            $map[$iata] = [
                'timezone' => $tz,
                'name' => self::nullableString($row['name'] ?? null),
                'city' => self::nullableString($row['city'] ?? null),
                'state' => self::nullableString($row['state'] ?? null),
            ];
        }
        return $map;
    }

    /**
     * @return list<array{iata: string, name: string, city: ?string, state: ?string, timezone: string}>
     */
    public static function seedRows(): array
    {
        $path = dirname(__DIR__, 2) . '/data/airports_us.php';
        if (!is_file($path)) {
            return [];
        }
        /** @var list<array{iata: string, name: string, city?: ?string, state?: ?string, timezone: string}> $rows */
        $rows = require $path;
        return $rows;
    }

    public static function normalizeIata(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));
        // Strip trailing city noise if someone stored "DEN - Denver"
        if (preg_match('/^([A-Z]{3})\b/', $code, $m) === 1) {
            $code = $m[1];
        }
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return null;
        }
        return $code;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s !== '' ? $s : null;
    }
}
