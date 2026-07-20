<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * IATA → IANA timezone lookup for interpreting naive segment wall-clock times.
 * Prefers the airports table; falls back to data/airports_us.php.
 */
final class AirportRepository
{
    /** @var array<string, string>|null iata => timezone */
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
        $iata = self::normalizeIata($code);
        if ($iata === null) {
            return null;
        }
        $map = $this->map();
        return $map[$iata] ?? null;
    }

    public function has(string $code): bool
    {
        return $this->timezoneForCode($code) !== null;
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
     * @return array<string, string>
     */
    private function map(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $map = [];
        if ($this->db !== null && $this->db->tableExists('airports')) {
            try {
                $rows = $this->db->fetchAll('SELECT iata, timezone FROM airports');
                foreach ($rows as $row) {
                    $iata = self::normalizeIata((string) ($row['iata'] ?? ''));
                    $tz = trim((string) ($row['timezone'] ?? ''));
                    if ($iata !== null && $tz !== '') {
                        $map[$iata] = $tz;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Airport table read failed; using seed file', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($map === []) {
            $map = self::seedMap();
        }

        $this->cache = $map;
        return $this->cache;
    }

    /**
     * @return array<string, string>
     */
    public static function seedMap(): array
    {
        $path = dirname(__DIR__, 2) . '/data/airports_us.php';
        if (!is_file($path)) {
            return [];
        }
        /** @var list<array{iata: string, name: string, timezone: string}> $rows */
        $rows = require $path;
        $map = [];
        foreach ($rows as $row) {
            $iata = self::normalizeIata($row['iata'] ?? null);
            $tz = trim((string) ($row['timezone'] ?? ''));
            if ($iata !== null && $tz !== '') {
                $map[$iata] = $tz;
            }
        }
        return $map;
    }

    /**
     * @return list<array{iata: string, name: string, timezone: string}>
     */
    public static function seedRows(): array
    {
        $path = dirname(__DIR__, 2) . '/data/airports_us.php';
        if (!is_file($path)) {
            return [];
        }
        /** @var list<array{iata: string, name: string, timezone: string}> $rows */
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
}
