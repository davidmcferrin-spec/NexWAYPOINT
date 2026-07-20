<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

/**
 * United Airlines eTicket / booking confirmation (and ignores ancillary receipts).
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
            '/Confirmation\s+LMXX9X/i', // won't match; fallback below
            '/for Confirmation\s+([A-Z0-9]{6})/i',
            '/confirmation\s+[–\-]\s*([A-Z0-9]{6})/i',
        ], $subject . "\n" . $text);

        if ($code === null && preg_match('/\b([A-Z0-9]{6})\b/', $subject, $m) === 1) {
            $code = $m[1];
            $this->recordField(true);
        }

        $flight = $this->extractFirstMatch([
            '/Flight\s+\d+\s+of\s+\d+\s+(UA\s*\d{1,4})/i',
            '/\b(UA\s*\d{1,4})\b/i',
        ], $text);

        $origin = $this->extractFirstMatch([
            '/\(([A-Z]{3})\)\s*\n.*?Huntsville|\(([A-Z]{3})\)[^\n]{0,40}\n[^\n]{0,40}\(([A-Z]{3})\)/is',
            '/Chicago[^\n]*\(([A-Z]{3})\)/i',
            '/\b([A-Z]{3})\b\s*\n\s*\d+h\s+\d+m\s*\n\s*\b([A-Z]{3})\b/',
        ], $text);

        // Prefer explicit airport lines.
        $originCode = null;
        $destCode = null;
        if (preg_match('/\(([A-Z]{3})\)[^\n]{0,80}\(([A-Z]{3})\)/s', $text, $m) === 1) {
            $originCode = $m[1];
            $destCode = $m[2];
            $this->recordField(true);
        } elseif (preg_match('/\b([A-Z]{3})\b\s*\n\s*(?:\d+h|\d+:\d+|\d+h\s+\d+m)[^\n]*\n\s*\b([A-Z]{3})\b/', $text, $m) === 1) {
            $originCode = $m[1];
            $destCode = $m[2];
            $this->recordField(true);
        } elseif (preg_match_all('/\(([A-Z]{3})\)/', $text, $m) >= 1 && count($m[1]) >= 2) {
            $originCode = $m[1][0];
            $destCode = $m[1][1];
            $this->recordField(true);
        } else {
            $this->recordField(false);
        }

        $dateRaw = $this->extractFirstMatch([
            '/\b((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+[A-Z][a-z]{2}\s+\d{1,2},?\s+\d{4})\b/',
            '/\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/',
            '/\b((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},?\s+\d{4})\b/',
        ], $text);

        $times = [];
        if (preg_match_all('/\b(\d{1,2}:\d{2}\s*[AP]M)\b/i', $text, $tm) > 0) {
            $times = $tm[1];
        }

        $depart = null;
        $arrive = null;
        if ($dateRaw !== null && isset($times[0])) {
            $depart = $this->combineDateAndTime($dateRaw, $times[0]);
        }
        if ($dateRaw !== null && isset($times[1])) {
            $arrive = $this->combineDateAndTime($dateRaw, $times[1]);
        }

        $flightNumber = $this->normalizeFlightNumber($flight, 'UA');
        if ($code === null || $flightNumber === null || $originCode === null || $destCode === null) {
            return null;
        }

        return [
            'kind' => 'flight',
            'event' => 'confirm',
            'confirmation_code' => strtoupper($code),
            'segments' => [[
                'confirmation_code' => strtoupper($code),
                'carrier_iata' => 'UA',
                'carrier_name' => 'United Airlines',
                'flight_number' => $flightNumber,
                'origin' => strtoupper($originCode),
                'destination' => strtoupper($destCode),
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ]],
        ];
    }
}
