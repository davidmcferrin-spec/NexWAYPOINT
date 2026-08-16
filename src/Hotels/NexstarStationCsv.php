<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

/**
 * Parse the Nexstar stations export into office_venues rows.
 *
 * Does not geocode or write the database — the seed script does that.
 */
final class NexstarStationCsv
{
    /** Known bad streets in the 2026-07-22 export, keyed by post_id. */
    private const ADDRESS_FIXES = [
        12512 => '2501 West Bradley Place, Chicago, Illinois 60618, United States',
    ];

    private const CITY_ALIASES = [
        'salt lake' => 'Salt Lake City',
    ];

    private const STATE_ABBREV = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
        'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT',
        'delaware' => 'DE', 'district of columbia' => 'DC', 'florida' => 'FL',
        'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL',
        'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY',
        'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
        'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
        'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT',
        'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH',
        'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
        'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA',
        'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
        'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT',
        'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
        'wisconsin' => 'WI', 'wyoming' => 'WY',
    ];

    /**
     * @return list<array{
     *   source_post_id: int,
     *   name: string,
     *   address_line1: ?string,
     *   city: ?string,
     *   state_region: ?string,
     *   postal_code: ?string,
     *   country: string,
     *   is_active: bool
     * }>
     */
    public static function parseFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to read {$path}");
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new \RuntimeException("CSV is empty: {$path}");
            }
            $header[0] = self::stripBom((string) $header[0]);
            $index = self::headerIndex($header);

            $out = [];
            $usedNames = [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                $title = trim((string) ($row[$index['title']] ?? ''));
                if ($title === '') {
                    continue;
                }
                $postId = (int) ($row[$index['post_id']] ?? 0);
                $status = strtolower(trim((string) ($row[$index['status']] ?? '')));
                $cityField = trim((string) ($row[$index['city']] ?? ''));
                $addressField = trim((string) ($row[$index['address']] ?? ''));
                if (isset(self::ADDRESS_FIXES[$postId])) {
                    $addressField = self::ADDRESS_FIXES[$postId];
                }

                [$marketCity, $marketState] = self::splitCityState($cityField);
                $addresses = self::splitAddresses($addressField);
                if ($addresses === []) {
                    $addresses = [''];
                }

                foreach ($addresses as $i => $rawAddress) {
                    $parsed = self::parseAddress($rawAddress, $marketCity, $marketState);
                    $name = $title;
                    if ($i > 0) {
                        $suffix = $parsed['city'] ?? ('site ' . ($i + 1));
                        $name = $title . ' (' . $suffix . ')';
                    }
                    $name = self::uniqueName($name, $usedNames);
                    $usedNames[strtolower($name)] = true;

                    $out[] = [
                        'source_post_id' => $postId,
                        'name' => $name,
                        'address_line1' => $parsed['address_line1'],
                        'city' => $parsed['city'] ?? $marketCity,
                        'state_region' => $parsed['state_region'] ?? $marketState,
                        'postal_code' => $parsed['postal_code'],
                        'country' => 'USA',
                        'is_active' => $status === 'publish',
                    ];
                }
            }
            return $out;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string|null> $header
     * @return array{post_id: int, title: int, status: int, city: int, address: int}
     */
    private static function headerIndex(array $header): array
    {
        $map = [];
        foreach ($header as $i => $col) {
            $map[strtolower(trim((string) $col))] = $i;
        }
        foreach (['post_id', 'title', 'status', 'city', 'address'] as $required) {
            if (!isset($map[$required])) {
                throw new \RuntimeException("CSV missing required column: {$required}");
            }
        }
        return [
            'post_id' => $map['post_id'],
            'title' => $map['title'],
            'status' => $map['status'],
            'city' => $map['city'],
            'address' => $map['address'],
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitCityState(string $field): array
    {
        $field = trim($field);
        if ($field === '') {
            return [null, null];
        }
        $parts = array_map('trim', explode(',', $field, 2));
        $city = $parts[0] !== '' ? $parts[0] : null;
        $state = isset($parts[1]) && $parts[1] !== '' ? self::normalizeState($parts[1]) : null;
        if ($city !== null) {
            $alias = self::CITY_ALIASES[strtolower($city)] ?? null;
            if ($alias !== null) {
                $city = $alias;
            }
        }
        return [$city, $state];
    }

    /**
     * @return list<string>
     */
    public static function splitAddresses(string $field): array
    {
        $field = trim($field);
        if ($field === '') {
            return [];
        }
        $parts = preg_split('/\s*;\s*/', $field) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B,");
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * @return array{address_line1: ?string, city: ?string, state_region: ?string, postal_code: ?string}
     */
    public static function parseAddress(string $raw, ?string $marketCity, ?string $marketState): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [
                'address_line1' => null,
                'city' => $marketCity,
                'state_region' => $marketState,
                'postal_code' => null,
            ];
        }

        if (str_contains($raw, ',')) {
            return self::parseCommaAddress($raw, $marketCity, $marketState);
        }
        return self::parseUnpunctuatedAddress($raw, $marketCity, $marketState);
    }

    /**
     * @return array{address_line1: ?string, city: ?string, state_region: ?string, postal_code: ?string}
     */
    private static function parseCommaAddress(string $raw, ?string $marketCity, ?string $marketState): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p) => $p !== ''));
        $unit = null;
        $postal = null;
        $state = null;
        $city = null;

        while ($parts !== []) {
            $last = (string) array_pop($parts);
            if (preg_match('/^(suite|ste\.?|unit|apt\.?)\s+(\S+)\s+(.+)$/i', $last, $glued) === 1) {
                $unit = trim($glued[1] . ' ' . $glued[2]);
                $parts[] = trim($glued[3]);
                continue;
            }
            if (self::isUnit($last)) {
                $unit = $last;
                continue;
            }
            if (self::isCountry($last)) {
                continue;
            }
            if (preg_match('/^(.+)\s+([A-Za-z]{2})$/', $last, $cityState) === 1) {
                $maybeState = self::normalizeState($cityState[2]);
                if ($maybeState !== null && self::normalizeState($cityState[1]) === null) {
                    $state = $state ?? $maybeState;
                    $city = $city ?? trim($cityState[1]);
                    break;
                }
            }
            $zip = self::extractPostal($last);
            if ($zip !== null) {
                $postal = $zip;
                $withoutZip = trim((string) preg_replace('/\b\d{5}(?:-\d{4})?\b/', '', $last));
                if ($withoutZip !== '') {
                    $asState = self::normalizeState($withoutZip);
                    if ($asState !== null) {
                        $state = $asState;
                    } elseif ($city === null) {
                        $city = $withoutZip;
                    } else {
                        $parts[] = $withoutZip;
                        break;
                    }
                }
                continue;
            }
            $asState = self::normalizeState($last);
            if ($asState !== null && $state === null) {
                $state = $asState;
                continue;
            }
            if ($city === null) {
                $city = $last;
                break;
            }
            $parts[] = $last;
            break;
        }

        $street = implode(', ', $parts);
        if ($city !== null && preg_match('/^\d/', $city) === 1) {
            $street = $street === '' ? $city : $city . ', ' . $street;
            $city = $marketCity;
            if ($marketCity !== null && preg_match(
                '/^(.*)\s+' . preg_quote($marketCity, '/') . '$/i',
                $street,
                $m
            ) === 1) {
                $street = trim($m[1]);
            }
        }
        if ($unit !== null) {
            $street = $street === '' ? $unit : $street . ', ' . $unit;
        }
        $street = self::nullable($street);

        return [
            'address_line1' => $street,
            'city' => $city ?? $marketCity,
            'state_region' => $state ?? $marketState,
            'postal_code' => $postal,
        ];
    }

    /**
     * @return array{address_line1: ?string, city: ?string, state_region: ?string, postal_code: ?string}
     */
    private static function parseUnpunctuatedAddress(string $raw, ?string $marketCity, ?string $marketState): array
    {
        $postal = self::extractPostal($raw);
        $work = $raw;
        if ($postal !== null) {
            $work = trim((string) preg_replace('/\s*' . preg_quote($postal, '/') . '\s*$/', '', $work));
        }
        $work = trim((string) preg_replace('/\s+/', ' ', $work));

        $state = $marketState;
        if (preg_match('/^(.*)\s+([A-Za-z]{2})$/', $work, $m) === 1) {
            $maybeState = self::normalizeState($m[2]);
            if ($maybeState !== null) {
                $state = $maybeState;
                $work = trim($m[1]);
            }
        }

        $city = $marketCity;
        $street = $work;
        $cityPrefix = 'West|East|North|South|Mount|Mt\.?|Saint|New|Fort|Ft\.?';
        if ($marketCity !== null && preg_match(
            '/^(.*)\s+((?:' . $cityPrefix . ')\s+' . preg_quote($marketCity, '/') . ')$/i',
            $work,
            $m
        ) === 1) {
            $street = trim($m[1]);
            $city = trim($m[2]);
        } elseif ($marketCity !== null && preg_match(
            '/^(.*)\s+' . preg_quote($marketCity, '/') . '$/i',
            $work,
            $m
        ) === 1) {
            $street = trim($m[1]);
            $city = $marketCity;
        } elseif (preg_match(
            '/^(.*)\s+((?:' . $cityPrefix . ')\s+\S+)$/i',
            $work,
            $m
        ) === 1) {
            $street = trim($m[1]);
            $city = trim($m[2]);
        } elseif (preg_match('/^(.*)\s+(\S+)$/', $work, $m) === 1
            && !self::isStreetSuffix($m[2])
        ) {
            $street = trim($m[1]);
            $city = trim($m[2]);
            if (preg_match('/^(City|Beach|Falls|Park|Springs|Junction|Island|Angeles|York)$/i', $city) === 1
                && preg_match('/^(.*)\s+(\S+)$/', $street, $m2) === 1
            ) {
                $city = trim($m2[2] . ' ' . $city);
                $street = trim($m2[1]);
            }
        }

        return [
            'address_line1' => self::nullable($street),
            'city' => $city,
            'state_region' => $state,
            'postal_code' => $postal,
        ];
    }

    public static function normalizeState(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z]{2}$/', $value) === 1) {
            return strtoupper($value);
        }
        $key = strtolower($value);
        return self::STATE_ABBREV[$key] ?? null;
    }

    private static function extractPostal(string $value): ?string
    {
        if (preg_match('/(?:^|[\s,])(\d{5}(?:-\d{4})?)\s*$/', $value, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private static function isCountry(string $value): bool
    {
        $key = strtolower(trim($value, " \t."));
        return in_array($key, ['usa', 'us', 'u.s.', 'u.s.a.', 'united states', 'united states of america'], true);
    }

    private static function isUnit(string $value): bool
    {
        return preg_match('/^(suite|ste\.?|unit|apt\.?|#)\s*[A-Za-z0-9-]+$/i', $value) === 1;
    }

    private static function isStreetSuffix(string $value): bool
    {
        return preg_match(
            '/^(Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Boulevard|Blvd|Lane|Ln|Way|Place|Pl|Court|Ct|Circle|Parkway|Pkwy|Freeway|Highway|Hwy|Pike|Trail|Plaza)$/i',
            $value
        ) === 1;
    }

    /**
     * @param array<string, true> $usedNames
     */
    private static function uniqueName(string $name, array $usedNames): string
    {
        $base = $name;
        $n = 2;
        while (isset($usedNames[strtolower($name)])) {
            $name = $base . ' (' . $n . ')';
            $n++;
        }
        return $name;
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }
        return $value;
    }
}
