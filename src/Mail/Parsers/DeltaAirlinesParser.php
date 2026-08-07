<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

/**
 * Delta receipts, trip details, time-change, and cancel emails.
 * Check-in / boarding / status mail is ignored (does not replace itineraries).
 */
final class DeltaAirlinesParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $subject = $message->subject;
        $text = $this->messageText($message);
        $subjectLower = strtolower($subject);

        if (
            str_contains($subjectLower, 'status update')
            || EmailConfirmationDetector::isAirlineCheckInSubject($subject)
        ) {
            return ['kind' => 'flight', 'event' => 'ignore', 'confirmation_code' => null, 'segments' => []];
        }

        $code = $this->extractConfirmationCode($subject . "\n" . $text);

        if (str_contains($subjectLower, 'cancel')) {
            if ($code === null) {
                return null;
            }
            return [
                'kind' => 'flight',
                'event' => 'cancel',
                'confirmation_code' => strtoupper($code),
                'segments' => [],
            ];
        }

        if (str_contains($subjectLower, 'time change') || str_contains($subjectLower, 'schedule change')) {
            return $this->parseTimeChange($text, $code);
        }

        // Trip details: "7:35 AM Mon, Apr 22 DL5394"
        $segments = $this->parseTripDetails($text, $code);
        if ($segments === []) {
            $segments = $this->parseReceipt($text, $code);
        }

        if ($code === null || $segments === []) {
            return null;
        }

        foreach ($segments as &$seg) {
            $seg['confirmation_code'] = strtoupper($code);
            $seg['carrier_iata'] = 'DL';
            $seg['carrier_name'] = $seg['carrier_name'] ?? 'Delta Air Lines';
        }
        unset($seg);

        return [
            'kind' => 'flight',
            'event' => $this->classifyItineraryEvent($subjectLower),
            'confirmation_code' => strtoupper($code),
            'segments' => $segments,
        ];
    }

    private function classifyItineraryEvent(string $subjectLower): string
    {
        foreach ([
            'new itinerary',
            'updated itinerary',
            'itinerary change',
            'itinerary update',
            'flight update',
            'schedule change',
            'time change',
            'rebooked',
            'rebook',
            'changed flight',
            'flight change',
        ] as $needle) {
            if (str_contains($subjectLower, $needle)) {
                return 'change';
            }
        }
        return 'confirm';
    }

    private function extractConfirmationCode(string $haystack): ?string
    {
        return $this->extractFirstMatch([
            '/FLIGHT CONFIRMATION\s*#\s*:?\s*([A-Z0-9]{6})/i',
            '/Trip Confirmation\s*#\s*:?\s*([A-Z0-9]{6})/i',
            '/Confirmation\s+Number\s*\n+\s*([A-Z0-9]{6})/i',
            '/Confirmation\s+Number\s*[:#]?\s*([A-Z0-9]{6})/i',
            '/Your Trip Confirmation\s*#\s*:?\s*([A-Z0-9]{6})/i',
            '/Confirmation\s*#\s*([A-Z0-9]{6})/i',
            '/Confirmation\s+code\s*[:#]?\s*([A-Z0-9]{6})/i',
        ], $haystack);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseTimeChange(string $text, ?string $code): ?array
    {
        if ($code === null) {
            return null;
        }
        $flight = $this->extractFirstMatch(['/Delta\s+(\d{1,4})/i', '/\bDL\s*(\d{1,4})\b/i'], $text);
        $flightNumber = $this->normalizeFlightNumber($flight, 'DL');

        $dateRaw = null;
        if (preg_match('/((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+[A-Z][a-z]+\s+\d{1,2}(?:,?\s+\d{4})?)/', $text, $dm) === 1) {
            $dateRaw = $dm[1];
        }
        if ($dateRaw !== null && !preg_match('/\d{4}/', $dateRaw) && preg_match('/\b(20\d{2})\b/', $text, $ym)) {
            $dateRaw .= ', ' . $ym[1];
        }

        $times = [];
        if (preg_match_all('/\b(\d{1,2}:\d{2}\s*[ap]m)\b/i', $text, $tm) > 0) {
            $times = $tm[1];
        }

        $depart = ($dateRaw && isset($times[0])) ? $this->combineDateAndTime($dateRaw, $times[0]) : null;
        $arrive = ($dateRaw && isset($times[1])) ? $this->combineDateAndTime($dateRaw, $times[1]) : null;

        $origin = null;
        $destination = null;
        if (preg_match('/Atlanta/i', $text)) {
            $origin = 'ATL';
        }
        if (preg_match('/Huntsville/i', $text)) {
            $destination = 'HSV';
        }

        if ($flightNumber === null) {
            return [
                'kind' => 'flight',
                'event' => 'change',
                'confirmation_code' => strtoupper($code),
                'segments' => [],
                'time_change' => [
                    'flight_number' => null,
                    'depart_dt' => $depart,
                    'arrive_dt' => $arrive,
                ],
            ];
        }

        return [
            'kind' => 'flight',
            'event' => 'change',
            'confirmation_code' => strtoupper($code),
            'segments' => [[
                'confirmation_code' => strtoupper($code),
                'carrier_iata' => 'DL',
                'carrier_name' => 'Delta Air Lines',
                'flight_number' => $flightNumber,
                'origin' => $origin,
                'destination' => $destination,
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ]],
            'time_change' => [
                'flight_number' => $flightNumber,
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseTripDetails(string $text, ?string $code): array
    {
        $segments = [];
        if (preg_match_all(
            '/(\d{1,2}:\d{2}\s*[AP]M)\s+((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+[A-Z][a-z]+\s+\d{1,2}(?:,?\s+\d{4})?)?\s*DL\s*(\d{1,4})/i',
            $text,
            $matches,
            PREG_SET_ORDER
        ) === false || $matches === []) {
            if (preg_match_all('/\b([A-Z]{3})\b\s*\n\s*(\d{1,2}:\d{2}\s*[AP]M)\s+([^ \n]+(?:,\s*[^ \n]+)*)\s+DL(\d{1,4})/i', $text, $m2, PREG_SET_ORDER)) {
                foreach ($m2 as $row) {
                    $depart = $this->combineDateAndTime($this->ensureYear($row[3], $text), $row[2]);
                    $segments[] = [
                        'confirmation_code' => $code,
                        'carrier_iata' => 'DL',
                        'carrier_name' => 'Delta Air Lines',
                        'flight_number' => $this->normalizeFlightNumber($row[4], 'DL'),
                        'origin' => strtoupper($row[1]),
                        'destination' => null,
                        'depart_dt' => $depart,
                        'arrive_dt' => null,
                    ];
                }
            }
            return $this->pairTripDetailAirports($text, $segments);
        }

        foreach ($matches as $row) {
            $datePart = trim((string) ($row[2] ?? ''));
            if ($datePart === '') {
                $datePart = $this->extractFirstMatch([
                    '/((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+[A-Z][a-z]+\s+\d{1,2},?\s+\d{4})/i',
                ], $text) ?? '';
            }
            $datePart = $this->ensureYear($datePart, $text);
            $depart = $datePart !== '' ? $this->combineDateAndTime($datePart, $row[1]) : null;
            $segments[] = [
                'confirmation_code' => $code,
                'carrier_iata' => 'DL',
                'carrier_name' => 'Delta Air Lines',
                'flight_number' => $this->normalizeFlightNumber($row[3], 'DL'),
                'origin' => null,
                'destination' => null,
                'depart_dt' => $depart,
                'arrive_dt' => null,
            ];
        }
        return $this->pairTripDetailAirports($text, $segments);
    }

    /**
     * @param list<array<string, mixed>> $segments
     * @return list<array<string, mixed>>
     */
    private function pairTripDetailAirports(string $text, array $segments): array
    {
        $codes = [];
        if (preg_match('/DEPARTURE\s*\n\s*([A-Z]{3})/i', $text, $m)) {
            $codes[] = strtoupper($m[1]);
        }
        if (preg_match_all('/\b([A-Z]{3})\b\s*\n\s*\d{1,2}:\d{2}\s*[AP]M/i', $text, $m2)) {
            foreach ($m2[1] as $c) {
                $c = strtoupper($c);
                if (!in_array($c, $codes, true) && !in_array($c, ['THE', 'AND', 'FOR', 'ALL'], true)) {
                    $codes[] = $c;
                }
            }
        }
        if (preg_match('/DESTINATION\s*\n\s*([A-Z]{3})/i', $text, $m3)) {
            $dest = strtoupper($m3[1]);
            if (!in_array($dest, $codes, true)) {
                $codes[] = $dest;
            }
        }

        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            $segments[$i]['origin'] = $codes[$i] ?? $segments[$i]['origin'];
            $segments[$i]['destination'] = $codes[$i + 1] ?? $segments[$i]['destination'];
        }
        return array_values(array_filter(
            $segments,
            static fn (array $s) => $s['flight_number'] !== null && $s['origin'] !== null && $s['destination'] !== null
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseReceipt(string $text, ?string $code): array
    {
        $segments = $this->parseReceiptSameLine($text, $code);
        if ($segments !== []) {
            return $segments;
        }
        return $this->parseReceiptStacked($text, $code);
    }

    /**
     * Classic: DELTA 3026 … \n HUNTSVILLE 05:12PM \n ATLANTA 07:16PM
     *
     * @return list<array<string, mixed>>
     */
    private function parseReceiptSameLine(string $text, ?string $code): array
    {
        $segments = [];
        if (!preg_match_all(
            '/DELTA\s+(\d{1,4})[^\n]{0,80}\n([A-Z][A-Z\s,\.]+?)\s+(\d{1,2}:\d{2}\s*[AP]M)\s*\n([A-Z][A-Z\s,\.\-]+?)\s+(\d{1,2}:\d{2}\s*[AP]M)/i',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        foreach ($matches as $row) {
            $flightNum = $row[1][0];
            $originCity = trim($row[2][0]);
            $departTime = $row[3][0];
            $destCity = trim($row[4][0]);
            $arriveTime = $row[5][0];
            $matchPos = (int) $row[0][1];

            $origin = $this->cityToAirport($originCity);
            $destination = $this->cityToAirport($destCity);
            if ($origin === null || $destination === null) {
                continue;
            }

            $dateRaw = $this->dateHeaderBefore($text, $matchPos);
            $dateNorm = $dateRaw !== null ? $this->normalizeDeltaDate($dateRaw, $text) : null;

            $segments[] = [
                'confirmation_code' => $code,
                'carrier_iata' => 'DL',
                'carrier_name' => 'Delta Air Lines',
                'flight_number' => $this->normalizeFlightNumber($flightNum, 'DL'),
                'origin' => $origin,
                'destination' => $destination,
                'depart_dt' => $dateNorm !== null ? $this->combineDateAndTime($dateNorm, $departTime) : null,
                'arrive_dt' => $dateNorm !== null ? $this->combineDateAndTime($dateNorm, $arriveTime) : null,
            ];
        }

        return array_values(array_filter(
            $segments,
            static fn (array $s) => $s['flight_number'] !== null && $s['origin'] !== null && $s['destination'] !== null
        ));
    }

    /**
     * Modern Flight Receipt (Proton/HTML→text): city and time on separate lines,
     * optional cabin line between flight number and cities, per-leg date headers.
     *
     * @return list<array<string, mixed>>
     */
    private function parseReceiptStacked(string $text, ?string $code): array
    {
        if (preg_match_all('/\bDELTA\s+(\d{1,4})\*?/i', $text, $matches, PREG_OFFSET_CAPTURE) === false
            || $matches[1] === []
        ) {
            return [];
        }

        $segments = [];
        $seen = [];
        foreach ($matches[1] as $hit) {
            $flightNum = $hit[0];
            $pos = (int) $hit[1];
            $chunk = substr($text, $pos, 600);

            // Skip seat-map style "DELTA 5210\n05A" — need city + time pairs.
            if (preg_match(
                '/^(\d{1,4})\*?[^\n]*\n'
                . '(?:[^\n]{0,80}\n){0,8}?'
                . '([A-Z][A-Za-z0-9\s,\.\-\/]{2,48}?)\s*\n\s*'
                . '(\d{1,2}:\d{2}\s*[AP]M)\s*\n\s*'
                . '([A-Z][A-Za-z0-9\s,\.\-\/]{2,48}?)\s*\n\s*'
                . '(\d{1,2}:\d{2}\s*[AP]M)/i',
                $chunk,
                $m
            ) !== 1) {
                continue;
            }

            $originCity = trim($m[2]);
            $destCity = trim($m[4]);
            if ($this->isNoiseCityLine($originCity) || $this->isNoiseCityLine($destCity)) {
                continue;
            }

            $origin = $this->cityToAirport($originCity);
            $destination = $this->cityToAirport($destCity);
            if ($origin === null || $destination === null) {
                continue;
            }

            $flightNumber = $this->normalizeFlightNumber($flightNum, 'DL');
            $dedupe = ($flightNumber ?? '') . '|' . $origin . '|' . $destination;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $dateRaw = $this->dateHeaderBefore($text, $pos);
            $dateNorm = $dateRaw !== null ? $this->normalizeDeltaDate($dateRaw, $text) : null;

            $segments[] = [
                'confirmation_code' => $code,
                'carrier_iata' => 'DL',
                'carrier_name' => 'Delta Air Lines',
                'flight_number' => $flightNumber,
                'origin' => $origin,
                'destination' => $destination,
                'depart_dt' => $dateNorm !== null ? $this->combineDateAndTime($dateNorm, $m[3]) : null,
                'arrive_dt' => $dateNorm !== null ? $this->combineDateAndTime($dateNorm, $m[5]) : null,
            ];
        }

        return array_values(array_filter(
            $segments,
            static fn (array $s) => $s['flight_number'] !== null
                && $s['origin'] !== null
                && $s['destination'] !== null
                && $s['depart_dt'] !== null
        ));
    }

    private function isNoiseCityLine(string $line): bool
    {
        $u = strtoupper(trim($line));
        return in_array($u, [
            'DEPART', 'ARRIVE', 'DEPARTURE', 'ARRIVAL', 'FLIGHT', 'SEAT',
            'PASSENGER INFO', 'MANAGE MY TRIP', 'FLIGHT RECEIPT',
        ], true)
            || str_starts_with($u, 'DELTA COMFORT')
            || str_starts_with($u, 'MAIN CABIN')
            || str_starts_with($u, 'FIRST CLASS')
            || preg_match('/^\d{1,2}[A-Z]$/', $u) === 1;
    }

    private function dateHeaderBefore(string $text, int $pos): ?string
    {
        $before = substr($text, 0, max(0, $pos));
        $candidates = [];

        if (preg_match_all(
            '/\b((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+\d{1,2}[A-Z]{3}\d{0,2})\b/i',
            $before,
            $m
        )) {
            foreach ($m[1] as $raw) {
                $candidates[] = $raw;
            }
        }
        if (preg_match_all(
            '/\b((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*\s+\d{1,2}\s+[A-Za-z]+\s+\d{4})\b/i',
            $before,
            $m2
        )) {
            foreach ($m2[1] as $raw) {
                $candidates[] = $raw;
            }
        }
        if (preg_match_all(
            '/\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/i',
            $before,
            $m3
        )) {
            foreach ($m3[1] as $raw) {
                $candidates[] = $raw;
            }
        }

        if ($candidates === []) {
            // Subject-style fallback: 10AUG26
            return $this->extractFirstMatch([
                '/\b(\d{1,2}[A-Z]{3}\d{2})\b/i',
            ], $text);
        }

        return $candidates[array_key_last($candidates)];
    }

    /**
     * Normalize Delta date headers to Y-m-d for combineDateAndTime.
     */
    private function normalizeDeltaDate(string $raw, string $text): ?string
    {
        $raw = trim($raw);

        // 10AUG26 (no weekday)
        if (preg_match('/^(\d{1,2})([A-Z]{3})(\d{2})$/i', $raw, $m) === 1) {
            $year = 2000 + (int) $m[3];
            return sprintf('%04d-%02d-%02d', $year, (int) $this->monthNum($m[2]), (int) $m[1]);
        }

        // Mon, 10AUG26 or Mon, 10AUG
        if (preg_match(
            '/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s*(\d{1,2})([A-Z]{3})(\d{2})?$/i',
            $raw,
            $m
        ) === 1) {
            $year = isset($m[3]) && $m[3] !== ''
                ? 2000 + (int) $m[3]
                : $this->inferYear($text);
            return sprintf('%04d-%02d-%02d', $year, (int) $this->monthNum($m[2]), (int) $m[1]);
        }

        // Mon 10 Aug 2026
        if (preg_match(
            '/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*\s+(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/i',
            $raw,
            $m
        ) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $this->monthNum($m[2]), (int) $m[1]);
        }

        // Already a flexible English date — pass through ensureYear.
        $withYear = $this->ensureYear($raw, $text);
        if ($withYear !== '' && preg_match('/\d{4}/', $withYear)) {
            return $withYear;
        }

        return null;
    }

    private function inferYear(string $text): int
    {
        if (preg_match('/\b(20\d{2})\b/', $text, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/\b\d{1,2}[A-Z]{3}(\d{2})\b/i', $text, $m) === 1) {
            return 2000 + (int) $m[1];
        }
        return (int) (new \DateTimeImmutable('today'))->format('Y');
    }

    private function ensureYear(string $dateRaw, string $text): string
    {
        $dateRaw = trim($dateRaw);
        if ($dateRaw === '') {
            return $dateRaw;
        }
        if (!preg_match('/\d{4}/', $dateRaw) && preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return $dateRaw . ', ' . $m[1];
        }
        return $dateRaw;
    }

    private function cityToAirport(string $city): ?string
    {
        $c = strtoupper(trim($city));
        $c = preg_replace('/\s+/', ' ', $c) ?? $c;
        $map = [
            'HUNTSVILLE' => 'HSV',
            'ATLANTA' => 'ATL',
            'DETROIT' => 'DTW',
            'SEATTLE' => 'SEA',
            'NYC-LAGUARDIA' => 'LGA',
            'NYC LAGUARDIA' => 'LGA',
            'NEW YORK-LAGUARDIA' => 'LGA',
            'NEW YORK' => 'LGA',
            'LAGUARDIA' => 'LGA',
            'JFK' => 'JFK',
            'NYC-JFK' => 'JFK',
            'NYC-KENNEDY' => 'JFK',
            'ORLANDO' => 'MCO',
            'ORLANDO INTL' => 'MCO',
            'LOS ANGELES' => 'LAX',
            'CHICAGO' => 'ORD',
            'WASHINGTON' => 'DCA',
            'LAS VEGAS' => 'LAS',
            'DALLAS' => 'DFW',
            'FORT WORTH' => 'DFW',
            'MINNEAPOLIS' => 'MSP',
            'SALT LAKE' => 'SLC',
            'BOSTON' => 'BOS',
            'DENVER' => 'DEN',
        ];
        foreach ($map as $name => $code) {
            if (str_contains($c, $name)) {
                return $code;
            }
        }
        if (preg_match('/\b([A-Z]{3})\b/', $c, $m) === 1
            && !in_array($m[1], ['THE', 'AND', 'FOR', 'ALL', 'USD', 'UTC'], true)
        ) {
            return $m[1];
        }
        return null;
    }
}
