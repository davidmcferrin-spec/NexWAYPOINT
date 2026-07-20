<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

/**
 * United Airlines eTicket / booking confirmation (and ignores ancillary receipts).
 * Emits one segments[] entry per UA flight when multi-leg itineraries appear.
 */
final class UnitedAirlinesParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $subject = $message->subject;
        $text = $this->messageText($message);
        $subjectLower = strtolower($subject);

        if (str_contains($subjectLower, 'thanks for your purchase')) {
            return ['kind' => 'flight', 'event' => 'ignore', 'confirmation_code' => null, 'segments' => []];
        }

        $code = $this->extractFirstMatch([
            '/Confirmation\s+Number:\s*([A-Z0-9]{6})/i',
            '/United\s+confirmation\s+number\s*:\s*([A-Z0-9]{6})/i',
            '/for Confirmation\s+([A-Z0-9]{6})/i',
            '/confirmation\s+[–\-]\s*([A-Z0-9]{6})/i',
        ], $subject . "\n" . $text);

        if ($code === null && preg_match('/\b([A-Z0-9]{6})\b/', $subject, $m) === 1) {
            $code = $m[1];
            $this->recordField(true);
        }

        $segments = $this->parseMultiLeg($text, $code);
        if ($segments === []) {
            $segments = $this->parseSingleLeg($text, $code);
        }

        if ($code === null || $segments === []) {
            return null;
        }

        foreach ($segments as &$seg) {
            $seg['confirmation_code'] = strtoupper($code);
            $seg['carrier_iata'] = 'UA';
            $seg['carrier_name'] = 'United Airlines';
        }
        unset($seg);

        $event = (str_contains($subjectLower, 'change') || str_contains($subjectLower, 'updated itinerary'))
            ? 'change'
            : 'confirm';

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
    private function parseMultiLeg(string $text, ?string $code): array
    {
        // "Flight 1 of 2 UA 1234" … next "Flight 2 of 2 UA 5678"
        if (preg_match_all(
            '/Flight\s+(\d+)\s+of\s+(\d+)\s+(UA\s*\d{1,4})\b(.*?)(?=Flight\s+\d+\s+of\s+\d+\s+UA\s*\d{1,4}\b|\z)/is',
            $text,
            $blocks,
            PREG_SET_ORDER
        ) < 1) {
            // Alternate: stacked "UA 1234" blocks with airport codes nearby.
            return $this->parseUaFlightBlocks($text);
        }

        $segments = [];
        foreach ($blocks as $block) {
            $chunk = $block[0];
            $flightNumber = $this->normalizeFlightNumber($block[3], 'UA');
            if ($flightNumber === null) {
                continue;
            }

            $airports = $this->extractAirportPair($chunk);
            if ($airports === null) {
                continue;
            }

            [$depart, $arrive] = $this->extractDepartArrive($chunk);
            $segments[] = [
                'confirmation_code' => $code !== null ? strtoupper($code) : null,
                'flight_number' => $flightNumber,
                'origin' => $airports[0],
                'destination' => $airports[1],
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ];
            $this->recordField(true);
        }

        return $segments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseUaFlightBlocks(string $text): array
    {
        if (preg_match_all('/\b(UA\s*\d{1,4})\b/i', $text, $flights, PREG_OFFSET_CAPTURE) < 2) {
            return [];
        }

        $segments = [];
        $matches = $flights[1];
        for ($i = 0; $i < count($matches); $i++) {
            $start = $matches[$i][1];
            $end = $matches[$i + 1][1] ?? strlen($text);
            $chunk = substr($text, $start, max(0, $end - $start));
            // Bound chunk size so we don't swallow the whole email for the last flight.
            if (strlen($chunk) > 1200) {
                $chunk = substr($chunk, 0, 1200);
            }

            $flightNumber = $this->normalizeFlightNumber($matches[$i][0], 'UA');
            if ($flightNumber === null) {
                continue;
            }
            $airports = $this->extractAirportPair($chunk);
            if ($airports === null) {
                continue;
            }
            [$depart, $arrive] = $this->extractDepartArrive($chunk);
            $segments[] = [
                'flight_number' => $flightNumber,
                'origin' => $airports[0],
                'destination' => $airports[1],
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ];
            $this->recordField(true);
        }

        // Need at least two distinct airport hops to treat as multi-leg.
        if (count($segments) < 2) {
            return [];
        }

        return $segments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseSingleLeg(string $text, ?string $code): array
    {
        $flight = $this->extractFirstMatch([
            '/Flight\s+\d+\s+of\s+\d+\s+(UA\s*\d{1,4})/i',
            '/\b(UA\s*\d{1,4})\b/i',
        ], $text);

        $airports = $this->extractAirportPair($text);
        if ($airports === null) {
            $this->recordField(false);
            return [];
        }

        [$depart, $arrive] = $this->extractDepartArrive($text);
        $flightNumber = $this->normalizeFlightNumber($flight, 'UA');
        if ($flightNumber === null) {
            return [];
        }

        return [[
            'confirmation_code' => $code !== null ? strtoupper($code) : null,
            'flight_number' => $flightNumber,
            'origin' => $airports[0],
            'destination' => $airports[1],
            'depart_dt' => $depart,
            'arrive_dt' => $arrive,
        ]];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractAirportPair(string $text): ?array
    {
        if (preg_match('/\(([A-Z]{3})\)[^\n]{0,120}\(([A-Z]{3})\)/s', $text, $m) === 1) {
            return [strtoupper($m[1]), strtoupper($m[2])];
        }
        if (preg_match('/\b([A-Z]{3})\b\s*\n\s*(?:\d+h|\d+:\d+|\d+h\s+\d+m)[^\n]*\n\s*\b([A-Z]{3})\b/', $text, $m) === 1) {
            return [strtoupper($m[1]), strtoupper($m[2])];
        }
        if (preg_match_all('/\(([A-Z]{3})\)/', $text, $m) >= 1 && count($m[1]) >= 2) {
            return [strtoupper($m[1][0]), strtoupper($m[1][1])];
        }
        // City (CODE) lines stacked.
        if (preg_match_all('/\(([A-Z]{3})\)/', $text, $m2) >= 1 && count($m2[1]) >= 2) {
            return [strtoupper($m2[1][0]), strtoupper($m2[1][1])];
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractDepartArrive(string $text): array
    {
        $dateRaw = $this->extractFirstMatch([
            '/\b((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+[A-Z][a-z]{2}\s+\d{1,2},?\s+\d{4})\b/',
            '/\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/',
            '/\b((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},?\s+\d{4})\b/',
        ], $text);

        $arriveDateRaw = null;
        if (preg_match_all(
            '/\b((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+[A-Z][a-z]{2}\s+\d{1,2},?\s+\d{4}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},?\s+\d{4})\b/',
            $text,
            $dm
        ) >= 1 && count($dm[1]) >= 2) {
            $arriveDateRaw = $dm[1][1];
        }

        $times = [];
        if (preg_match_all('/\b(\d{1,2}:\d{2}\s*[AP]M)\b/i', $text, $tm) > 0) {
            $times = $tm[1];
        }

        $depart = null;
        $arrive = null;
        if ($dateRaw !== null && isset($times[0])) {
            $depart = $this->combineDateAndTime($dateRaw, $times[0]);
        }
        if (isset($times[1])) {
            $arriveDate = $arriveDateRaw ?? $dateRaw;
            if ($arriveDate !== null) {
                $arrive = $this->combineDateAndTime($arriveDate, $times[1]);
            }
        }

        // Same-date red-eye: arrive clock earlier than depart → next calendar day.
        if ($depart !== null && $arrive !== null && $arrive <= $depart) {
            try {
                $arriveDt = (new \DateTimeImmutable($arrive))->modify('+1 day');
                $arrive = $arriveDt->format('Y-m-d H:i:s');
            } catch (\Exception) {
                // keep original
            }
        }

        return [$depart, $arrive];
    }
}
