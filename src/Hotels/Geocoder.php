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

    /**
     * Free-text place / hotel / address search for the property form.
     *
     * @return list<array{
     *   display_name: string,
     *   lat: float,
     *   lon: float,
     *   hotel_name: ?string,
     *   address_line1: ?string,
     *   city: ?string,
     *   state_region: ?string,
     *   postal_code: ?string,
     *   country: ?string,
     *   phone: ?string,
     *   website: ?string
     * }>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }
        $limit = max(1, min(8, $limit));

        $params = [
            'q' => $query,
            'format' => 'json',
            'addressdetails' => 1,
            'extratags' => 1,
            'limit' => $limit,
        ];
        // Bias toward US unless another country is named in the query.
        if (preg_match('/\b(Canada|Mexico|United Kingdom|\bUK\b|France|Germany|Australia)\b/i', $query) !== 1) {
            $params['countrycodes'] = 'us';
        }

        $rows = $this->requestNominatimRows($params, $query);
        $out = [];
        foreach ($rows as $row) {
            $parsed = $this->parseNominatimRow($row);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   display_name: string,
     *   lat: float,
     *   lon: float,
     *   hotel_name: ?string,
     *   address_line1: ?string,
     *   city: ?string,
     *   state_region: ?string,
     *   postal_code: ?string,
     *   country: ?string,
     *   phone: ?string,
     *   website: ?string
     * }|null
     */
    public function parseNominatimRow(array $row): ?array
    {
        if (!isset($row['lat'], $row['lon'])) {
            return null;
        }
        $address = is_array($row['address'] ?? null) ? $row['address'] : [];
        $house = trim((string) ($address['house_number'] ?? ''));
        $road = trim((string) ($address['road'] ?? $address['pedestrian'] ?? $address['footway'] ?? ''));
        $addressLine1 = null;
        if ($road !== '') {
            $addressLine1 = $house !== '' ? trim($house . ' ' . $road) : $road;
        }

        $city = $this->firstAddressValue($address, [
            'city', 'town', 'village', 'municipality', 'hamlet', 'suburb',
        ]);
        $state = $this->firstAddressValue($address, ['state', 'region', 'state_district']);
        if ($state !== null && strlen($state) > 2) {
            // Prefer 2-letter when Nominatim gave full name and ISO3166-2-lvl4 exists.
            $iso = trim((string) ($address['ISO3166-2-lvl4'] ?? ''));
            if (preg_match('/^[A-Z]{2}-([A-Z]{2})$/', $iso, $m) === 1) {
                $state = $m[1];
            }
        }
        $postal = $this->firstAddressValue($address, ['postcode']);
        $country = $this->firstAddressValue($address, ['country']);
        if ($country !== null) {
            $country = $this->normalizeCountry($country) === 'United States' ? 'USA' : $country;
        }

        $hotelName = $this->firstAddressValue($address, [
            'hotel', 'tourism', 'amenity', 'building', 'railway',
        ]);
        $display = trim((string) ($row['display_name'] ?? ''));
        if ($hotelName === null && $display !== '') {
            $hotelName = trim(explode(',', $display, 2)[0]);
        }

        $extras = is_array($row['extratags'] ?? null) ? $row['extratags'] : [];

        return [
            'display_name' => $display !== '' ? $display : ($addressLine1 ?? 'Unknown place'),
            'lat' => (float) $row['lat'],
            'lon' => (float) $row['lon'],
            'hotel_name' => $hotelName,
            'address_line1' => $addressLine1,
            'city' => $city,
            'state_region' => $state,
            'postal_code' => $postal,
            'country' => $country,
            'phone' => $this->extractPhone($extras),
            'website' => $this->extractWebsite($extras),
        ];
    }

    /**
     * @param array<string, mixed> $extras
     */
    public function extractPhone(array $extras): ?string
    {
        foreach (['phone', 'contact:phone', 'contact:mobile', 'telephone'] as $key) {
            if (!isset($extras[$key])) {
                continue;
            }
            $value = trim((string) $extras[$key]);
            // OSM sometimes lists multiple numbers separated by ; or /
            if (preg_match('/^([^;\/]+)/', $value, $m) === 1) {
                $value = trim($m[1]);
            }
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $extras
     */
    public function extractWebsite(array $extras): ?string
    {
        foreach (['website', 'contact:website', 'url', 'contact:url'] as $key) {
            if (!isset($extras[$key])) {
                continue;
            }
            $value = trim((string) $extras[$key]);
            if (preg_match('/^([^;,\s]+)/', $value, $m) === 1) {
                $value = trim($m[1]);
            }
            if ($value === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $value)) {
                $value = 'https://' . ltrim($value, '/');
            }
            return $value;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $address
     * @param list<string> $keys
     */
    private function firstAddressValue(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($address[$key])) {
                continue;
            }
            $value = trim((string) $address[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
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
        $rows = $this->requestNominatimRows($params, $logLabel);
        if ($rows === [] || !isset($rows[0]['lat'], $rows[0]['lon'])) {
            return null;
        }
        return [
            'lat' => (float) $rows[0]['lat'],
            'lon' => (float) $rows[0]['lon'],
        ];
    }

    /**
     * @param array<string, scalar> $params
     * @return list<array<string, mixed>>
     */
    private function requestNominatimRows(array $params, string $logLabel): array
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
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === []) {
            $this->logger->info('Nominatim returned no match', ['query' => $logLabel]);
            return [];
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
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
