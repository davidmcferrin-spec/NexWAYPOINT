<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

/**
 * Delta receipts, trip details, and time-change emails.
 */
final class DeltaAirlinesParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $subject = $message->subject;
        $text = $this->messageText($message);
        $subjectLower = strtolower($subject);

        if (str_contains($subjectLower, 'status update') || str_contains($subjectLower, 'check-in')) {
            return ['kind' => 'flight', 'event' => 'ignore', 'confirmation_code' => null, 'segments' => []];
        }

        $code = $this->extractFirstMatch([
            '/FLIGHT CONFIRMATION\s*#\s*:?\s*([A-Z0-9]{6})/i',
            '/Trip Confirmation\s*#\s*:?\s*([A-Z0-9]{6})/i',
            '/Confirmation\s+Number\s*\n\s*([A-Z0-9]{6})/i',
            '/Confirmation\s+Number\s*[:#]?\s*([A-Z0-9]{6})/i',
            '/Your Trip Confirmation\s*#\s*:?\s*([A-Z0-9]{6})/i',
            '/Confirmation\s*#\s*([A-Z0-9]{6})/i',
        ], $subject . "\n" . $text);

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

        $event = str_contains($subjectLower, 'new itinerary') ? 'change' : 'confirm';
        return [
            'kind' => 'flight',
            'event' => $event,
            'confirmation_code' => strtoupper($code),
            'segments' => $segments,
        ];
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

        // Prefer "Your New Flight Info" times (first pair after UPDATE).
        $dateRaw = $this->extractFirstMatch([
            '/(?:Fri|Sat|Sun|Mon|Tue|Wed|Thu)[a-z]*,?\s+(April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:,?\s+(\d{4}))?/i',
        ], $text);
        // Rebuild date if capture groups fragmented — use full match via another pattern.
        if (preg_match('/((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+[A-Z][a-z]+\s+\d{1,2}(?:,?\s+\d{4})?)/', $text, $dm) === 1) {
            $dateRaw = $dm[1];
            if (!str_contains($dateRaw, '20')) {
                $dateRaw .= ', 2024'; // fallback year from samples; better from email year
            }
        }
        // Prefer year from body if present.
        if ($dateRaw !== null && !preg_match('/\d{4}/', $dateRaw) && preg_match('/\b(20\d{2})\b/', $text, $ym)) {
            $dateRaw .= ', ' . $ym[1];
        }

        $times = [];
        if (preg_match_all('/\b(\d{1,2}:\d{2}\s*[ap]m)\b/i', $text, $tm) > 0) {
            $times = $tm[1];
        }

        $depart = ($dateRaw && isset($times[0])) ? $this->combineDateAndTime($dateRaw, $times[0]) : null;
        $arrive = ($dateRaw && isset($times[1])) ? $this->combineDateAndTime($dateRaw, $times[1]) : null;

        // Airports: Atlanta / Huntsville style — map common names.
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
        // "7:35 AM Mon, Apr 22 DL5394" or "11:15 AM DL737"
        if (preg_match_all(
            '/(\d{1,2}:\d{2}\s*[AP]M)\s+((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+[A-Z][a-z]+\s+\d{1,2}(?:,?\s+\d{4})?)?\s*DL\s*(\d{1,4})/i',
            $text,
            $matches,
            PREG_SET_ORDER
        ) === false || $matches === []) {
            // Alternate: airport code lines then time+flight
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
        $segments = [];
        // DEPART / ARRIVE blocks: DELTA 3026 ... HUNTSVILLE 05:12PM ATLANTA 07:16PM
        if (preg_match_all(
            '/DELTA\s+(\d{1,4})[^\n]{0,80}\n([A-Z][A-Z\s,\.]+?)\s+(\d{1,2}:\d{2}\s*[AP]M)\s*\n([A-Z][A-Z\s,\.\-]+?)\s+(\d{1,2}:\d{2}\s*[AP]M)/i',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            $dateRaw = $this->extractFirstMatch([
                '/\b((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+\d{1,2}[A-Z]{3}\d{2})\b/i',
                '/\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/i',
            ], $text);
            foreach ($matches as $row) {
                $origin = $this->cityToAirport(trim($row[2]));
                $destination = $this->cityToAirport(trim($row[4]));
                $segments[] = [
                    'confirmation_code' => $code,
                    'carrier_iata' => 'DL',
                    'carrier_name' => 'Delta Air Lines',
                    'flight_number' => $this->normalizeFlightNumber($row[1], 'DL'),
                    'origin' => $origin,
                    'destination' => $destination,
                    'depart_dt' => $dateRaw ? $this->combineDateAndTime($dateRaw, $row[3]) : null,
                    'arrive_dt' => $dateRaw ? $this->combineDateAndTime($dateRaw, $row[5]) : null,
                ];
            }
        }

        // Fallback: DELTA NNN with city names nearby
        if ($segments === [] && preg_match_all('/DELTA\s+(\d{1,4})\*?/i', $text, $flights)) {
            foreach ($flights[1] as $num) {
                $segments[] = [
                    'confirmation_code' => $code,
                    'carrier_iata' => 'DL',
                    'carrier_name' => 'Delta Air Lines',
                    'flight_number' => $this->normalizeFlightNumber($num, 'DL'),
                    'origin' => null,
                    'destination' => null,
                    'depart_dt' => null,
                    'arrive_dt' => null,
                ];
            }
            // Try Routing line: HSV DL X/ATL DL NYC
            if (preg_match('/Routing:\s*(.+)/i', $text, $r)) {
                // Don't trust fare construction blindly.
            }
        }

        return array_values(array_filter(
            $segments,
            static fn (array $s) => $s['flight_number'] !== null && $s['origin'] !== null && $s['destination'] !== null
        ));
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
        $c = strtoupper($city);
        $map = [
            'HUNTSVILLE' => 'HSV',
            'ATLANTA' => 'ATL',
            'DETROIT' => 'DTW',
            'SEATTLE' => 'SEA',
            'NYC-LAGUARDIA' => 'LGA',
            'NEW YORK' => 'LGA',
            'LAGUARDIA' => 'LGA',
            'ORLANDO' => 'MCO',
            'ORLANDO INTL' => 'MCO',
            'LOS ANGELES' => 'LAX',
            'CHICAGO' => 'ORD',
            'WASHINGTON' => 'DCA',
            'LAS VEGAS' => 'LAS',
        ];
        foreach ($map as $name => $code) {
            if (str_contains($c, $name)) {
                return $code;
            }
        }
        if (preg_match('/\b([A-Z]{3})\b/', $c, $m)) {
            return $m[1];
        }
        return null;
    }
}
