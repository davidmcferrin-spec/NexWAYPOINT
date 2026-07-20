<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Logger;

/**
 * OpenStreetMap Nominatim geocoder with on-disk cache.
 * Used by the hotel map and office/venue catalog.
 */
final class Geocoder
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT = 'NexWAYPOINT/1.0 (self-hosted hotel map; https://nexwaypoint.area51consulting.com)';
    private const CACHE_VERSION = 'v3';
    private const MISS_TTL_SECONDS = 3600;

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
        bool $forceRefresh = false,
    ): ?array {
        $addressLine1 = $this->trimOrNull($addressLine1);
        $city = $this->trimOrNull($city);
        $stateRegion = $this->trimOrNull($stateRegion);
        $postalCode = $this->trimOrNull($postalCode);
        $country = $this->normalizeCountry($country);
        $normalizedStreet = $this->normalizeStreetAddress($addressLine1);

        if ($city === null && $normalizedStreet === null && $addressLine1 === null) {
            return null;
        }

        $streetForLookup = $normalizedStreet ?? $addressLine1;
        $cacheKey = $this->cacheKey($streetForLookup, $city, $stateRegion, $postalCode, $country);
        if (!$forceRefresh) {
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                return $cached === [] ? null : $cached;
            }
        }

        $candidates = [];
        if ($streetForLookup !== null) {
            $candidates[] = $streetForLookup;
        }
        if ($addressLine1 !== null && strcasecmp($addressLine1, (string) $streetForLookup) !== 0) {
            $candidates[] = $addressLine1;
        }

        $result = null;
        foreach ($candidates as $street) {
            $result = $this->fetchNominatimStructured($street, $city, $stateRegion, $postalCode, $country);
            if ($result !== null) {
                break;
            }
            $parts = array_values(array_filter([$street, $city, $stateRegion, $postalCode, $country]));
            $result = $this->fetchNominatimFreeform(implode(', ', $parts));
            if ($result !== null) {
                break;
            }
        }

        // City-only centroid is a last resort and often wrong when a street was given
        // (e.g. Washington, DC centroid sits on the White House). Skip it if we had a street.
        if ($result === null && $streetForLookup === null && $city !== null) {
            $result = $this->fetchNominatimStructured(null, $city, $stateRegion, $postalCode, $country);
            if ($result === null) {
                $parts = array_values(array_filter([$city, $stateRegion, $country]));
                $result = $this->fetchNominatimFreeform(implode(', ', $parts));
            }
        }

        $this->writeCache($cacheKey, $result);
        return $result;
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    public function geocodeCity(?string $city, ?string $stateRegion, ?string $country, bool $forceRefresh = false): ?array
    {
        if ($city === null || trim($city) === '') {
            return null;
        }
        return $this->geocode(null, $city, $stateRegion, null, $country, $forceRefresh);
    }

    public function normalizeCountry(?string $country): string
    {
        $country = $this->trimOrNull($country) ?? 'USA';
        $upper = strtoupper($country);
        return match ($upper) {
            'USA', 'US', 'U.S.', 'U.S.A.', 'UNITED STATES OF AMERICA' => 'United States',
            'UK', 'GB', 'GREAT BRITAIN' => 'United Kingdom',
            default => $country,
        };
    }

    /**
     * Fix common US street typos/abbreviations that break Nominatim
     * (notably "Capital" vs "Capitol" in DC).
     */
    public function normalizeStreetAddress(?string $address): ?string
    {
        $address = $this->trimOrNull($address);
        if ($address === null) {
            return null;
        }

        // Extremely common DC / government-area typo.
        $address = preg_replace('/\bCapital\b/i', 'Capitol', $address) ?? $address;

        // Directional abbreviations: "N." / "N " → North (not mid-word).
        $address = preg_replace('/\bN\.?\s+(?=[A-Za-z])/i', 'North ', $address) ?? $address;
        $address = preg_replace('/\bS\.?\s+(?=[A-Za-z])/i', 'South ', $address) ?? $address;
        $address = preg_replace('/\bE\.?\s+(?=[A-Za-z])/i', 'East ', $address) ?? $address;
        $address = preg_replace('/\bW\.?\s+(?=[A-Za-z])/i', 'West ', $address) ?? $address;

        // Street type abbreviations (avoid matching inside "Street").
        $address = preg_replace('/\bSt\.?(?=\s|,|$)/i', 'Street', $address) ?? $address;
        $address = preg_replace('/\bAve\.?(?=\s|,|$)/i', 'Avenue', $address) ?? $address;
        $address = preg_replace('/\bBlvd\.?(?=\s|,|$)/i', 'Boulevard', $address) ?? $address;
        $address = preg_replace('/\bRd\.?(?=\s|,|$)/i', 'Road', $address) ?? $address;
        $address = preg_replace('/\bDr\.?(?=\s|,|$)/i', 'Drive', $address) ?? $address;

        $address = preg_replace('/\s+/', ' ', $address) ?? $address;
        return trim($address);
    }

    /**
     * @return array{lat: float, lon: float}|list{}|null
     *         Hit → coords; soft miss → []; missing/expired → null
     */
    private function readCache(string $cacheKey): ?array
    {
        $path = $this->cachePath($cacheKey);
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
        if (!empty($data['miss'])) {
            $missAt = (int) ($data['miss_at'] ?? 0);
            if ($missAt > 0 && (time() - $missAt) < self::MISS_TTL_SECONDS) {
                return [];
            }
            @unlink($path);
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
    private function writeCache(string $cacheKey, ?array $result): void
    {
        $path = $this->cachePath($cacheKey);
        $payload = $result ?? ['miss' => true, 'miss_at' => time()];
        @file_put_contents($path, json_encode($payload));
    }

    private function cachePath(string $cacheKey): string
    {
        return $this->cacheDir . '/' . hash('sha256', $cacheKey) . '.json';
    }

    private function cacheKey(
        ?string $addressLine1,
        ?string $city,
        ?string $stateRegion,
        ?string $postalCode,
        string $country,
    ): string {
        return strtolower(implode('|', [
            self::CACHE_VERSION,
            $addressLine1 ?? '',
            $city ?? '',
            $stateRegion ?? '',
            $postalCode ?? '',
            $country,
        ]));
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function fetchNominatimStructured(
        ?string $addressLine1,
        ?string $city,
        ?string $stateRegion,
        ?string $postalCode,
        string $country,
    ): ?array {
        $params = [
            'format' => 'json',
            'limit' => 1,
        ];
        if ($addressLine1 !== null) {
            $params['street'] = $addressLine1;
        }
        if ($city !== null) {
            $params['city'] = $city;
        }
        if ($stateRegion !== null) {
            $params['state'] = $stateRegion;
        }
        if ($postalCode !== null) {
            $params['postalcode'] = $postalCode;
        }
        $params['country'] = $country;
        if ($country === 'United States') {
            $params['countrycodes'] = 'us';
        }

        return $this->requestNominatim($params, 'structured:' . ($addressLine1 ?? '') . ' / ' . ($city ?? ''));
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function fetchNominatimFreeform(string $query): ?array
    {
        if (trim($query) === '') {
            return null;
        }
        $params = [
            'q' => $query,
            'format' => 'json',
            'limit' => 1,
        ];
        if (stripos($query, 'United States') !== false || preg_match('/\b(USA|US)\b/i', $query)) {
            $params['countrycodes'] = 'us';
        }
        return $this->requestNominatim($params, $query);
    }

    /**
     * @param array<string, scalar> $params
     * @return array{lat: float, lon: float}|null
     */
    private function requestNominatim(array $params, string $logLabel): ?array
    {
        $elapsed = microtime(true) - $this->lastRequestAt;
        if ($this->lastRequestAt > 0 && $elapsed < 1.05) {
            usleep((int) ((1.05 - $elapsed) * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);

        $url = self::NOMINATIM_URL . '?' . http_build_query($params);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => 10,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $this->logger->warning('Nominatim geocode failed', ['query' => $logLabel]);
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === [] || !isset($decoded[0]['lat'], $decoded[0]['lon'])) {
            $this->logger->info('Nominatim returned no match', ['query' => $logLabel]);
            return null;
        }

        return [
            'lat' => (float) $decoded[0]['lat'],
            'lon' => (float) $decoded[0]['lon'],
        ];
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
