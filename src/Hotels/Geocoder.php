<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Logger;

/**
 * OpenStreetMap Nominatim geocoder with on-disk cache.
 * Used by the hotel map view when properties lack latitude/longitude.
 */
final class Geocoder
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT = 'NexWAYPOINT/1.0 (self-hosted hotel map; https://nexwaypoint.area51consulting.com)';

    private string $cacheDir;
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly Logger $logger,
        ?string $cacheDir = null,
    ) {
        $this->cacheDir = $cacheDir ?? (NEXWAYPOINT_ROOT . '/storage/cache/geocode');
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    public function geocode(
        ?string $addressLine1,
        ?string $city,
        ?string $stateRegion,
        ?string $postalCode,
        ?string $country,
    ): ?array {
        $parts = [];
        if ($addressLine1 !== null && trim($addressLine1) !== '') {
            $parts[] = trim($addressLine1);
        }
        if ($city !== null && trim($city) !== '') {
            $parts[] = trim($city);
        }
        if ($stateRegion !== null && trim($stateRegion) !== '') {
            $parts[] = trim($stateRegion);
        }
        if ($postalCode !== null && trim($postalCode) !== '') {
            $parts[] = trim($postalCode);
        }
        $country = ($country !== null && trim($country) !== '') ? trim($country) : 'USA';
        $parts[] = $country;

        $hasCity = $city !== null && trim($city) !== '';
        $hasAddress = $addressLine1 !== null && trim($addressLine1) !== '';
        if (!$hasCity && !$hasAddress) {
            return null;
        }

        $query = implode(', ', $parts);
        $cached = $this->readCache($query);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchNominatim($query);
        $this->writeCache($query, $result);
        return $result;
    }

    /**
     * City-level lookup (faster shared cache across hotels in the same city).
     *
     * @return array{lat: float, lon: float}|null
     */
    public function geocodeCity(?string $city, ?string $stateRegion, ?string $country): ?array
    {
        if ($city === null || trim($city) === '') {
            return null;
        }
        return $this->geocode(null, $city, $stateRegion, null, $country);
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function readCache(string $query): ?array
    {
        $path = $this->cachePath($query);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (array_key_exists('miss', $data) && $data['miss'] === true) {
            return null;
        }
        if (!isset($data['lat'], $data['lon'])) {
            return null;
        }
        return ['lat' => (float) $data['lat'], 'lon' => (float) $data['lon']];
    }

    /**
     * @param array{lat: float, lon: float}|null $result
     */
    private function writeCache(string $query, ?array $result): void
    {
        $path = $this->cachePath($query);
        $payload = $result ?? ['miss' => true];
        @file_put_contents($path, json_encode($payload));
    }

    private function cachePath(string $query): string
    {
        return $this->cacheDir . '/' . hash('sha256', strtolower(trim($query))) . '.json';
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function fetchNominatim(string $query): ?array
    {
        // Nominatim asks for ≤1 request/second.
        $elapsed = microtime(true) - $this->lastRequestAt;
        if ($this->lastRequestAt > 0 && $elapsed < 1.05) {
            usleep((int) ((1.05 - $elapsed) * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);

        $url = self::NOMINATIM_URL . '?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'limit' => 1,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => 8,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $this->logger->warning('Nominatim geocode failed', ['query' => $query]);
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === [] || !isset($decoded[0]['lat'], $decoded[0]['lon'])) {
            $this->logger->info('Nominatim returned no match', ['query' => $query]);
            return null;
        }

        return [
            'lat' => (float) $decoded[0]['lat'],
            'lon' => (float) $decoded[0]['lon'],
        ];
    }
}
