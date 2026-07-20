<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

/**
 * Breeze Airways confirm / cancel (IATA MX).
 */
final class BreezeAirlinesParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $text = $this->messageText($message);
        $subjectLower = strtolower($message->subject);

        $code = $this->extractFirstMatch([
            '/Confirmation\s+Number:\s*([A-Z0-9]{6})/i',
            '/\b([A-Z0-9]{6})\b/',
        ], $message->subject . "\n" . $text);

        $flight = $this->extractFirstMatch([
            '/Flight\s+(MX\s*\d{1,4})/i',
            '/\b(MX\s*\d{1,4})\b/i',
        ], $text);

        $origin = null;
        $destination = null;
        if (preg_match('/\(([A-Z]{3})\)[^\n]{0,120}\(([A-Z]{3})\)/s', $text, $m) === 1) {
            $origin = $m[1];
            $destination = $m[2];
            $this->recordField(true);
        } elseif (preg_match('/\b([A-Z]{3})\b\s*\n\s*\b([A-Z]{3})\b/', $text, $m) === 1) {
            // After city names, bare codes appear.
            $origin = $m[1];
            $destination = $m[2];
            $this->recordField(true);
        } else {
            $this->recordField(false);
        }

        // Prefer airport-code lines near "Harry Reid" style.
        if (preg_match_all('/\(([A-Z]{3})\)/', $text, $all) && count($all[1]) >= 2) {
            $origin = $all[1][0];
            $destination = $all[1][1];
        }

        $dateRaw = $this->extractFirstMatch([
            '/\b((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4})\b/i',
        ], $text);

        $times = [];
        if (preg_match_all('/\b(\d{1,2}:\d{2}\s*[ap]m)\b/i', $text, $tm) > 0) {
            $times = $tm[1];
        }

        $depart = ($dateRaw && isset($times[0])) ? $this->combineDateAndTime($dateRaw, $times[0]) : null;
        $arrive = ($dateRaw && isset($times[1])) ? $this->combineDateAndTime($dateRaw, $times[1]) : null;
        $flightNumber = $this->normalizeFlightNumber($flight, 'MX');

        if ($code === null) {
            return null;
        }

        if (str_contains($subjectLower, 'cancel')) {
            return [
                'kind' => 'flight',
                'event' => 'cancel',
                'confirmation_code' => strtoupper($code),
                'segments' => [],
            ];
        }

        if ($flightNumber === null || $origin === null || $destination === null) {
            return null;
        }

        return [
            'kind' => 'flight',
            'event' => 'confirm',
            'confirmation_code' => strtoupper($code),
            'segments' => [[
                'confirmation_code' => strtoupper($code),
                'carrier_iata' => 'MX',
                'carrier_name' => 'Breeze Airways',
                'flight_number' => $flightNumber,
                'origin' => strtoupper($origin),
                'destination' => strtoupper($destination),
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ]],
        ];
    }
}
