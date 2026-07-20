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
            $stop = ['THE', 'AND', 'FOR', 'YOU', 'ARE', 'ALL', 'NEW', 'NOW', 'HAS', 'WAS', 'CAN', 'MAY', 'GET', 'SEE', 'OUR', 'ANY', 'BUT', 'HOW', 'WHO', 'OUT', 'ONE', 'TWO', 'TOP', 'APP', 'PDF', 'HTML', 'COM', 'USA', 'USD', 'EST', 'CST', 'PST', 'EDT', 'CDT', 'GMT', 'UTC', 'FAQ', 'YES', 'VIP', 'PRO', 'AA', 'LLC'];
            foreach ($am[1] ?? [] as $code) {
                if (!in_array($code, $stop, true)) {
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
