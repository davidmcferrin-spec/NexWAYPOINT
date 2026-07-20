<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

final class AmtrakParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $text = $this->messageText($message);
        $subjectLower = strtolower($message->subject);

        $code = $this->extractFirstMatch([
            '/Reservation Number\s*-\s*([A-Z0-9]{5,8})/i',
            '/Reservation Number\s*[:\-]?\s*([A-Z0-9]{5,8})/i',
        ], $text);

        if (str_contains($subjectLower, 'refund')) {
            if ($code === null) {
                return null;
            }
            return [
                'kind' => 'train',
                'event' => 'cancel',
                'confirmation_code' => strtoupper($code),
                'segments' => [],
            ];
        }

        $train = $this->extractFirstMatch([
            '/TRAIN\s+(\d{1,5})\s*:/i',
            '/Train\s*#?\s*(\d{1,5})/i',
        ], $text);

        $origin = null;
        $destination = null;
        // "New York, NY - Moynihan ... to Washington, DC - Union Station"
        if (preg_match('/TRAIN\s+\d+:\s*(.+?)\s+to\s+(.+?)\s*\(/i', $text, $m) === 1) {
            $origin = $this->stationLabel(trim($m[1]));
            $destination = $this->stationLabel(trim($m[2]));
            $this->recordField(true);
        } elseif (preg_match('/\n(.+?)\s+to\s+(.+?)\s*\(One-Way\)/i', $text, $m) === 1) {
            $origin = $this->stationLabel(trim($m[1]));
            $destination = $this->stationLabel(trim($m[2]));
            $this->recordField(true);
        } else {
            $this->recordField(false);
        }

        $depart = null;
        if (preg_match('/Depart\s+(\d{1,2}:\d{2}\s*[AP]M),\s*([A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i', $text, $dm) === 1) {
            $depart = $this->combineDateAndTime($dm[2], $dm[1]);
        }

        if ($code === null || $train === null || $origin === null || $destination === null) {
            return null;
        }

        $event = str_contains($subjectLower, 'updated') ? 'change' : 'confirm';

        return [
            'kind' => 'train',
            'event' => $event,
            'confirmation_code' => strtoupper($code),
            'segments' => [[
                'confirmation_code' => strtoupper($code),
                'carrier_iata' => null,
                'carrier_name' => 'Amtrak',
                'flight_number' => $train, // train number stored in flight_number column
                'origin' => $origin,
                'destination' => $destination,
                'depart_dt' => $depart,
                'arrive_dt' => null,
                'segment_type' => 'train',
            ]],
        ];
    }

    private function stationLabel(string $raw): string
    {
        // Prefer "City, ST" prefix.
        if (preg_match('/^([^,\-]+,\s*[A-Z]{2})/', $raw, $m) === 1) {
            return trim($m[1]);
        }
        return trim($raw);
    }
}
