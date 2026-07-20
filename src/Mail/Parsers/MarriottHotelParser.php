<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

final class MarriottHotelParser extends ParserBase
{
    /** @var array<string, string> */
    private const STATE_NAMES = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
        'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
        'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
        'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
        'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
        'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
        'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
        'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
        'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
        'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
        'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
        'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC',
    ];

    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $subject = $message->subject;
        $text = $this->messageText($message);
        $haystack = $subject . "\n" . $text;
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
        ], $haystack);

        $property = $this->extractPropertyName($subject, $text);

        [$checkInRaw, $checkOutRaw] = $this->extractStayDateRaw($text);
        $checkIn = $this->parseFlexibleDate($checkInRaw);
        $checkOut = $this->parseFlexibleDate($checkOutRaw);

        [$address, $city, $state] = $this->extractLocation($text);

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
            'property_name' => $property,
            'brand' => 'Marriott',
            'address' => $address,
            'city' => $city,
            'state_region' => $state,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_type' => $roomType,
        ];
    }

    private function extractPropertyName(string $subject, string $text): ?string
    {
        $property = $this->extractFirstMatch([
            '/Reservation Confirmation\s*#[A-Z0-9]+\s+for\s+(.+?)(?:\s*$|\n)/i',
            '/Confirmation\s*#[A-Z0-9]+\s+for\s+(.+?)(?:\s*$|\n)/i',
        ], $subject);

        if ($property !== null) {
            return trim($property);
        }

        return $this->extractFirstMatch([
            '/^([^\n]+(?:Hotel|Inn|Suites|Courtyard|Residence|Sheraton|Westin|Tribute Portfolio|Autograph Collection|Design Hotels)[^\n]*)$/im',
            '/\n([^\n]+(?:Hotel|Inn|Suites|Courtyard|Residence|Sheraton|Westin|Tribute)[^\n]*)\n/i',
        ], $text);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractStayDateRaw(string $text): array
    {
        $checkInRaw = null;
        $checkOutRaw = null;

        // Marriott templates put the label on its own line, date on the next.
        if (preg_match(
            '/Check-In:\s*([A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i',
            $text,
            $m
        ) === 1) {
            $checkInRaw = $m[1];
            $this->recordField(true);
        }
        if (preg_match(
            '/Check-Out:\s*([A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4})/i',
            $text,
            $m
        ) === 1) {
            $checkOutRaw = $m[1];
            $this->recordField(true);
        }

        // Range: Tue, Jun 24, 2025 - Thu, Jun 26, 2025
        if (($checkInRaw === null || $checkOutRaw === null)
            && preg_match(
                '/([A-Za-z]{3},\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4})\s*-\s*([A-Za-z]{3},\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4})/',
                $text,
                $rm
            ) === 1
        ) {
            $checkInRaw = $checkInRaw ?? $rm[1];
            $checkOutRaw = $checkOutRaw ?? $rm[2];
            $this->recordField(true);
        }

        if ($checkInRaw === null) {
            $this->recordField(false);
        }
        if ($checkOutRaw === null) {
            $this->recordField(false);
        }

        return [$checkInRaw, $checkOutRaw];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} address, city, state
     */
    private function extractLocation(string $text): array
    {
        // "3632 Foo St, City, ST 60613" or "3630 North Clark Street Chicago, Illinois 60613 USA"
        if (preg_match(
            '/(\d{1,5}\s+[A-Za-z0-9. ]+?),\s*([A-Za-z .]+),\s*([A-Z]{2})\s+(\d{5})(?:-\d{4})?/',
            $text,
            $m
        ) === 1) {
            $this->recordField(true);
            return [trim($m[1] . ', ' . $m[2] . ', ' . $m[3] . ' ' . $m[4]), trim($m[2]), $m[3]];
        }

        if (preg_match(
            '/(\d{1,5}\s+[A-Za-z0-9. ]+?)\s+([A-Za-z .]+),\s*([A-Za-z][a-z]+(?:\s+[A-Za-z][a-z]+)?)\s+(\d{5})(?:-\d{4})?(?:\s+USA)?/i',
            $text,
            $m
        ) === 1) {
            $city = trim($m[2]);
            $state = $this->normalizeState($m[3]);
            $address = trim($m[1] . ' ' . $city . ', ' . ($state ?? $m[3]) . ' ' . $m[4]);
            $this->recordField(true);
            return [$address, $city, $state];
        }

        if (preg_match('/([A-Za-z .]+),\s*([A-Za-z][a-z]+(?:\s+[A-Za-z][a-z]+)?|[A-Z]{2})\s+(\d{5})/i', $text, $m) === 1) {
            $city = trim($m[1]);
            // Avoid matching "Hotel Zachary, Chicago" style — require a ZIP on the same clause.
            if (!preg_match('/\b(Hotel|Inn|Suites|Confirmation|Reservation)\b/i', $city)) {
                $this->recordField(true);
                return [null, $city, $this->normalizeState($m[2])];
            }
        }

        $this->recordField(false);
        return [null, null, null];
    }

    private function normalizeState(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^[A-Za-z]{2}$/', $raw) === 1) {
            return strtoupper($raw);
        }
        $key = strtolower($raw);
        return self::STATE_NAMES[$key] ?? null;
    }
}
