<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

final class MarriottHotelParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $subject = $message->subject;
        $text = $this->messageText($message);
        $subjectLower = strtolower($subject);

        if (str_contains($subjectLower, 'stay at ') && !str_contains($subjectLower, 'confirmation')) {
            return ['kind' => 'hotel', 'event' => 'ignore', 'confirmation_code' => null];
        }
        if (str_contains($subjectLower, 'thank you for choosing our hotel')) {
            return ['kind' => 'hotel', 'event' => 'ignore', 'confirmation_code' => null];
        }

        $code = $this->extractFirstMatch([
            '/Confirmation\s+Number:\s*([A-Z0-9]{6,12})/i',
            '/Reservation Confirmation\s*#\s*([A-Z0-9]{6,12})/i',
            '/Confirmation\s*#\s*([A-Z0-9]{6,12})/i',
        ], $subject . "\n" . $text);

        $property = $this->extractFirstMatch([
            '/Reservation Confirmation #[A-Z0-9]+ for (.+)/i',
            '/\n([^\n]+(?:Hotel|Inn|Suites|Courtyard|Residence|Sheraton|Westin|Tribute)[^\n]*)\n/i',
        ], $subject . "\n" . $text);

        $checkInRaw = $this->extractFirstMatch([
            '/Check-In:\s*\n?\s*([A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i',
            '/Check-In:\s*([A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i',
        ], $text);
        $checkOutRaw = $this->extractFirstMatch([
            '/Check-Out:\s*\n?\s*([A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i',
            '/Check-Out:\s*([A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i',
        ], $text);

        // Range line: Tue, Jun 24, 2025 – Thu, Jun 26, 2025
        if (($checkInRaw === null || $checkOutRaw === null)
            && preg_match('/([A-Za-z]{3},\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4})\s*[–\-]\s*([A-Za-z]{3},\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4})/', $text, $rm)
        ) {
            $checkInRaw = $checkInRaw ?? $rm[1];
            $checkOutRaw = $checkOutRaw ?? $rm[2];
            $this->recordField(true);
        }

        $checkIn = $this->parseFlexibleDate($checkInRaw);
        $checkOut = $this->parseFlexibleDate($checkOutRaw);

        $address = $this->extractPattern(
            '/(\d{1,5}\s[A-Za-z0-9.\s]{2,60},\s*[A-Za-z\s]+,\s*[A-Za-z]+\s+\d{5})/',
            $text
        );

        $city = null;
        $state = null;
        if ($address !== null && preg_match('/,\s*([A-Za-z\s]+),\s*([A-Z]{2})\s+\d{5}/', $address, $lm)) {
            $city = trim($lm[1]);
            $state = $lm[2];
        } elseif (preg_match('/,\s*([A-Za-z\s]+),\s*([A-Za-z]+)\s+\d{5}/', $text, $lm)) {
            $city = trim($lm[1]);
            $state = strlen($lm[2]) === 2 ? strtoupper($lm[2]) : null;
            $this->recordField(true);
        }

        $roomType = $this->extractFirstMatch([
            '/Room Type\s*\n\s*([^\n]+)/i',
            '/Guest room[^\n]*/i',
        ], $text);

        if ($code === null || $property === null || $checkIn === null || $checkOut === null) {
            return null;
        }

        return [
            'kind' => 'hotel',
            'event' => 'confirm',
            'confirmation_code' => $code,
            'property_name' => trim($property),
            'brand' => 'Marriott',
            'address' => $address,
            'city' => $city,
            'state_region' => $state,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_type' => $roomType,
        ];
    }
}
