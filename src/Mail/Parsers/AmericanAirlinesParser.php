<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

/**
 * American Airlines confirmations (Schema.org JSON-LD), cancels, and rebooks.
 */
final class AmericanAirlinesParser extends ParserBase
{
    /** @var list<string> */
    private const AIRPORT_STOP = [
        'THE', 'AND', 'FOR', 'YOU', 'ARE', 'ALL', 'NEW', 'NOW', 'APP', 'PDF',
        'USA', 'USD', 'EST', 'CST', 'PST', 'EDT', 'CDT', 'GMT', 'UTC', 'FAQ',
        'VIP', 'PRO', 'AA', 'LLC', 'HAS', 'WAS', 'CAN', 'MAY', 'GET', 'SEE',
        'OUR', 'ANY', 'BUT', 'HOW', 'WHO', 'OUT', 'ONE', 'TWO', 'TOP', 'YES',
        'HTML', 'COM',
    ];

    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $subject = $message->subject;
        $text = $this->messageText($message);
        $subjectLower = strtolower($subject);

        if (str_contains($subjectLower, 'refund')) {
            return ['kind' => 'flight', 'event' => 'ignore', 'confirmation_code' => null, 'segments' => []];
        }

        if (str_contains($subjectLower, 'cancel')) {
            $code = $this->extractFirstMatch([
                '/Confirmation\s+code:\s*([A-Z0-9]{6})/i',
                '/Confirmation\s*(?:code|number|#)?\s*[:\-]?\s*([A-Z0-9]{6})/i',
            ], $text);
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

        $segments = $this->parseJsonLdSegments($message->bodyHtml);
        $event = (str_contains($subjectLower, 'rebook') || str_contains($subjectLower, 'new itinerary'))
            ? 'change'
            : 'confirm';

        if ($segments === [] && str_contains($subjectLower, 'rebook')) {
            $segments = $this->parseRebookHtml($text);
            $event = 'change';
        }

        if ($segments === []) {
            $segments = $this->parsePlainConfirmation($text, $subject);
        }

        $code = $segments[0]['confirmation_code'] ?? null;
        if ($code === null) {
            $code = $this->extractFirstMatch([
                '/Confirmation\s+code:\s*([A-Z0-9]{6})/i',
                '/Confirmation\s*(?:code|number|#)?\s*[:\-]?\s*([A-Z0-9]{6})/i',
            ], $subject . "\n" . $text);
        }

        if ($code === null || $segments === []) {
            return null;
        }

        foreach ($segments as &$seg) {
            $seg['confirmation_code'] = strtoupper($code);
            $seg['carrier_iata'] = $seg['carrier_iata'] ?? 'AA';
            $seg['carrier_name'] = $seg['carrier_name'] ?? 'American Airlines';
        }
        unset($seg);

        $this->recordField(true);
        return [
            'kind' => 'flight',
            'event' => $event,
            'confirmation_code' => strtoupper($code),
            'segments' => $segments,
        ];
    }

    /**
     * Plain-text / forwarded AA confirmation (no Schema.org JSON-LD).
     * Emits one segment per AA flight (round-trips, connections, multi-city).
     *
     * @return list<array<string, mixed>>
     */
    private function parsePlainConfirmation(string $text, string $subject): array
    {
        $segments = $this->parseAaReceiptLegs($text);
        if ($segments !== []) {
            return $segments;
        }

        return $this->parsePlainSingleLegFallback($text, $subject);
    }

    /**
     * AA receipt layout: weekday date headers, each with one or more
     * origin / depart / AA n / dest / arrive stacks.
     *
     * @return list<array<string, mixed>>
     */
    private function parseAaReceiptLegs(string $text): array
    {
        $dateRe = '/((?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i';
        if (preg_match_all($dateRe, $text, $dm, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $segments = [];
        $dates = $dm[1];
        for ($i = 0; $i < count($dates); $i++) {
            $dateRaw = $dates[$i][0];
            $start = $dates[$i][1];
            $end = $dates[$i + 1][1] ?? strlen($text);
            $chunk = substr($text, $start, max(0, $end - $start));
            if (preg_match(
                '/\b(?:Manage your trip|Your purchase|Bag information|Book a hotel)\b/i',
                $chunk,
                $cut,
                PREG_OFFSET_CAPTURE
            ) === 1) {
                $chunk = substr($chunk, 0, (int) $cut[0][1]);
            }

            foreach ($this->parseAaFlightsInDateChunk($chunk, $dateRaw) as $seg) {
                $segments[] = $seg;
            }
        }

        return $segments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseAaFlightsInDateChunk(string $chunk, string $dateRaw): array
    {
        if (preg_match_all('/\bAA\s*(\d{1,4})\b/i', $chunk, $fm, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $segments = [];
        $n = count($fm[0]);
        for ($i = 0; $i < $n; $i++) {
            $flightNumber = $this->normalizeFlightNumber($fm[1][$i][0], 'AA');
            if ($flightNumber === null) {
                continue;
            }

            $flightPos = $fm[0][$i][1];
            $nextPos = $fm[0][$i + 1][1] ?? strlen($chunk);
            $lookBack = substr($chunk, max(0, $flightPos - 350), min(350, $flightPos));
            $lookAhead = substr(
                $chunk,
                $flightPos + strlen($fm[0][$i][0]),
                max(0, min(500, $nextPos - $flightPos))
            );

            $origin = $this->lastStandaloneAirport($lookBack);
            $destination = $this->firstStandaloneAirport($lookAhead);
            if ($origin === null || $destination === null || $origin === $destination) {
                continue;
            }

            $departTime = $this->lastTimeToken($lookBack);
            $arriveTime = $this->firstTimeToken($lookAhead);
            $depart = $departTime !== null
                ? $this->parseFlexibleDateTime($dateRaw . ' ' . $departTime)
                : $this->parseFlexibleDateTime($dateRaw);
            $arrive = $arriveTime !== null
                ? $this->parseFlexibleDateTime($dateRaw . ' ' . $arriveTime)
                : null;

            $this->recordField(true); // flight
            $this->recordField(true); // route
            $this->recordField($depart !== null);

            $segments[] = [
                'confirmation_code' => null,
                'carrier_iata' => 'AA',
                'carrier_name' => 'American Airlines',
                'flight_number' => $flightNumber,
                'origin' => $origin,
                'destination' => $destination,
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ];
        }

        return $segments;
    }

    /**
     * Older one-way / sparse plain bodies without weekday date headers.
     *
     * @return list<array<string, mixed>>
     */
    private function parsePlainSingleLegFallback(string $text, string $subject): array
    {
        $origin = null;
        $destination = null;
        if (preg_match('/\(([A-Z]{3})\s*[-–—]\s*([A-Z]{3})\)/', $subject, $m) === 1) {
            $origin = $m[1];
            $destination = $m[2];
        }

        if (!preg_match('/\bAA\s*(\d{1,4})\b/i', $text, $fm)) {
            return [];
        }
        $flightNumber = $this->normalizeFlightNumber($fm[1], 'AA');
        if ($flightNumber === null) {
            return [];
        }

        if ($origin === null || $destination === null) {
            $codes = $this->standaloneAirports($text);
            if (count($codes) >= 2) {
                $origin = $codes[0];
                $destination = $codes[1];
            }
        }

        if ($origin === null || $destination === null) {
            return [];
        }

        $depart = null;
        if (preg_match(
            '/((?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i',
            $text,
            $dm
        ) === 1) {
            if (preg_match('/\b(\d{1,2}:\d{2}\s*[AP]M)\b/i', $text, $tm) === 1) {
                $depart = $this->parseFlexibleDateTime($dm[1] . ' ' . $tm[1]);
            } else {
                $depart = $this->parseFlexibleDateTime($dm[1]);
            }
        }

        $this->recordField(true);
        $this->recordField(true);
        $this->recordField($depart !== null);

        return [[
            'confirmation_code' => null,
            'carrier_iata' => 'AA',
            'carrier_name' => 'American Airlines',
            'flight_number' => $flightNumber,
            'origin' => $origin,
            'destination' => $destination,
            'depart_dt' => $depart,
            'arrive_dt' => null,
        ]];
    }

    /**
     * @return list<string>
     */
    private function standaloneAirports(string $text): array
    {
        if (preg_match_all('/(?:^|\n)\s*>?\s*([A-Z]{3})\s*(?:\n|$)/', $text, $am) < 1) {
            return [];
        }
        $codes = [];
        foreach ($am[1] as $code) {
            if (!in_array($code, self::AIRPORT_STOP, true)) {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    private function lastStandaloneAirport(string $text): ?string
    {
        $codes = $this->standaloneAirports($text);
        if ($codes === []) {
            return null;
        }
        return $codes[count($codes) - 1];
    }

    private function firstStandaloneAirport(string $text): ?string
    {
        $codes = $this->standaloneAirports($text);
        return $codes[0] ?? null;
    }

    private function lastTimeToken(string $text): ?string
    {
        if (preg_match_all('/\b(\d{1,2}:\d{2}\s*[AP]M)\b/i', $text, $tm) < 1) {
            return null;
        }
        $times = $tm[1];
        return $times[count($times) - 1] ?? null;
    }

    private function firstTimeToken(string $text): ?string
    {
        if (preg_match('/\b(\d{1,2}:\d{2}\s*[AP]M)\b/i', $text, $tm) !== 1) {
            return null;
        }
        return $tm[1];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJsonLdSegments(string $html): array
    {
        $segments = [];
        $seen = [];
        foreach ($this->flattenJsonLd($this->extractJsonLd($html)) as $node) {
            $type = $node['@type'] ?? null;
            $types = is_array($type) ? $type : [$type];
            if (!in_array('FlightReservation', $types, true)) {
                continue;
            }
            $rf = $node['reservationFor'] ?? null;
            if (!is_array($rf)) {
                continue;
            }
            $airline = is_array($rf['airline'] ?? null) ? $rf['airline'] : [];
            $dep = is_array($rf['departureAirport'] ?? null) ? $rf['departureAirport'] : [];
            $arr = is_array($rf['arrivalAirport'] ?? null) ? $rf['arrivalAirport'] : [];
            $iata = strtoupper((string) ($airline['iataCode'] ?? 'AA'));
            $flightNumber = $this->normalizeFlightNumber((string) ($rf['flightNumber'] ?? ''), $iata);
            $depart = $this->parseFlexibleDateTime(isset($rf['departureTime']) ? (string) $rf['departureTime'] : null);
            $arrive = $this->parseFlexibleDateTime(isset($rf['arrivalTime']) ? (string) $rf['arrivalTime'] : null);
            $origin = isset($dep['iataCode']) ? strtoupper((string) $dep['iataCode']) : null;
            $destination = isset($arr['iataCode']) ? strtoupper((string) $arr['iataCode']) : null;
            $code = isset($node['reservationNumber']) ? strtoupper((string) $node['reservationNumber']) : null;

            $key = ($flightNumber ?? '') . '|' . ($depart ?? '');
            if ($flightNumber === null || $origin === null || $destination === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $segments[] = [
                'confirmation_code' => $code,
                'carrier_iata' => $iata,
                'carrier_name' => (string) ($airline['name'] ?? 'American Airlines'),
                'flight_number' => $flightNumber,
                'origin' => $origin,
                'destination' => $destination,
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ];
        }
        return $segments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseRebookHtml(string $text): array
    {
        $segments = [];
        if (preg_match_all('/\bAA\s+(\d{1,4})\b/', $text, $matches) === false) {
            return [];
        }
        $numbers = array_values(array_unique($matches[1]));
        $airports = [];
        if (preg_match_all('/\b([A-Z]{3})\b/', $text, $am) === 1 || true) {
            foreach ($am[1] ?? [] as $code) {
                if (!in_array($code, self::AIRPORT_STOP, true)) {
                    $airports[] = $code;
                }
            }
            $airports = array_values(array_unique($airports));
        }
        foreach ($numbers as $i => $num) {
            $segments[] = [
                'confirmation_code' => null,
                'carrier_iata' => 'AA',
                'carrier_name' => 'American Airlines',
                'flight_number' => $this->normalizeFlightNumber($num, 'AA'),
                'origin' => $airports[$i] ?? ($airports[0] ?? null),
                'destination' => $airports[$i + 1] ?? ($airports[1] ?? null),
                'depart_dt' => null,
                'arrive_dt' => null,
            ];
        }
        return array_values(array_filter(
            $segments,
            static fn (array $s) => $s['flight_number'] !== null && $s['origin'] !== null && $s['destination'] !== null
        ));
    }
}
