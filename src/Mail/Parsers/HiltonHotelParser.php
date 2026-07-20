<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

final class HiltonHotelParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $subject = $message->subject;
        $text = $this->messageText($message);
        $haystack = $subject . "\n" . $text;
        $subjectLower = strtolower($subject);

        if (str_contains($subjectLower, 'enjoyed your stay') || str_contains($subjectLower, 'come again soon')) {
            return ['kind' => 'hotel', 'event' => 'ignore', 'confirmation_code' => null];
        }

        if (str_contains($subjectLower, 'cancellation') || str_contains($subjectLower, 'canceled')
            || str_contains($subjectLower, 'cancelled')) {
            $code = $this->extractFirstMatch([
                '/Cancellation\s*#\s*([0-9]{6,12})/i',
                '/Cancellation\s*#\s*([A-Z0-9]{6,12})/i',
            ], $haystack);
            $property = $this->extractPropertyName($text);
            [$checkIn, $checkOut] = $this->extractStayDates($subject, $text);
            if ($code === null) {
                return null;
            }
            return [
                'kind' => 'hotel',
                'event' => 'cancel',
                'confirmation_code' => $code,
                'cancellation_code' => $code,
                'property_name' => $property,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ];
        }

        $code = $this->extractFirstMatch([
            '/Confirmation\s*#\s*([0-9]{6,12})/i',
            '/Confirmation\s*#([0-9]{6,12})/i',
        ], $haystack);

        $property = $this->extractPropertyName($text);

        [$checkInDate, $checkOutDate] = $this->extractStayDates($subject, $text);

        [$city, $state] = $this->extractCityState($text);

        if ($code === null || $property === null || $checkInDate === null) {
            return null;
        }
        if ($checkOutDate === null) {
            $checkOutDate = date('Y-m-d', strtotime($checkInDate . ' +1 day'));
            $this->recordField(true);
        }

        return [
            'kind' => 'hotel',
            'event' => 'confirm',
            'confirmation_code' => $code,
            'property_name' => $property,
            'brand' => 'Hilton',
            'address' => null,
            'city' => $city,
            'state_region' => $state,
            'check_in' => $checkInDate,
            'check_out' => $checkOutDate,
            'room_type' => null,
        ];
    }

    private function extractPropertyName(string $text): ?string
    {
        $this->fieldsAttempted++;
        $patterns = [
            // "New York Hilton Midtown", "Hilton Garden Inn …", etc.
            '/^([^\n]*(?:Hilton|Embassy Suites|Hampton Inn|Homewood Suites|Home2 Suites|Spark by Hilton|Motto by Hilton|Signia by Hilton|Tapestry Collection|Curio Collection|LXR Hotels|Waldorf Astoria|Conrad)[^\n]*)$/im',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($matches as $m) {
                $value = trim($m[1]);
                if ($value === '' || str_contains($value, '@')
                    || preg_match('/^(From|To|Subject|Date|Sent)\s*:/i', $value) === 1
                    || preg_match('/\bh6\.hilton\.com\b/i', $value) === 1
                ) {
                    continue;
                }
                // Prefer the property line over marketing chrome.
                if (preg_match('/\b(Hotels?\s*&\s*Resorts|Honors|Confirmed)\b/i', $value) === 1
                    && !preg_match('/\b(Inn|Suites|Midtown|Downtown|Airport|Garden|Embassy|Hampton|Homewood|Home2|Signia|Tapestry|Curio|Waldorf|Conrad|Motto|Spark)\b/i', $value)
                ) {
                    continue;
                }
                $this->fieldsFound++;
                return $value;
            }
        }
        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string} check_in, check_out as Y-m-d
     */
    private function extractStayDates(string $subject, string $text): array
    {
        $checkIn = null;
        $checkOut = null;
        $haystack = $subject . "\n" . $text;

        // Night rows: "Jul-07-2026 - Jul-09-2026" (Mon-DD-YYYY)
        if (preg_match_all(
            '/([A-Z][a-z]{2})-(\d{2})-(\d{4})\s*-\s*([A-Z][a-z]{2})-(\d{2})-(\d{4})/i',
            $text,
            $nights,
            PREG_SET_ORDER
        ) && $nights !== []) {
            $first = $nights[0];
            $last = $nights[count($nights) - 1];
            $checkIn = sprintf('%s-%s-%s', $first[3], $this->monthNum($first[1]), $first[2]);
            $checkOut = sprintf('%s-%s-%s', $last[6], $this->monthNum($last[4]), $last[5]);
            $this->recordField(true);
            $this->recordField(true);
            return [$checkIn, $checkOut];
        }

        // Legacy night rows: "07-Jul-2026 - 09-Jul-2026"
        if (preg_match_all(
            '/(\d{2})-([A-Z][a-z]{2})-(\d{4})\s*-\s*(\d{2})-([A-Z][a-z]{2})-(\d{4})/i',
            $text,
            $nights,
            PREG_SET_ORDER
        ) && $nights !== []) {
            $first = $nights[0];
            $last = $nights[count($nights) - 1];
            $checkIn = sprintf('%s-%s-%s', $first[3], $this->monthNum($first[2]), $first[1]);
            $checkOut = sprintf('%s-%s-%s', $last[6], $this->monthNum($last[5]), $last[4]);
            $this->recordField(true);
            $this->recordField(true);
            return [$checkIn, $checkOut];
        }

        // Subject: Your Jul-07-2026 Confirmation
        if (preg_match('/Your\s+([A-Z][a-z]{2})-(\d{2})-(\d{4})\s+Confirmation/i', $subject, $sm) === 1) {
            $checkIn = sprintf('%s-%s-%s', $sm[3], $this->monthNum($sm[1]), $sm[2]);
            $this->recordField(true);
        }

        // Body: Your reservation for Tuesday Jul 07, 2026 / Jul-07-2026
        if ($checkIn === null
            && preg_match(
                '/reservation for\s+(?:[A-Za-z]+day\s+)?([A-Z][a-z]{2})[-\s]+(\d{1,2}),?\s+(\d{4})/i',
                $haystack,
                $rd
            ) === 1
        ) {
            $checkIn = sprintf('%s-%s-%02d', $rd[3], $this->monthNum($rd[1]), (int) $rd[2]);
            $this->recordField(true);
        } elseif ($checkIn === null) {
            $this->recordField(false);
        }

        // Check Out block: "Jul 13" / "Jul​ 13" above "Check Out:"
        if (preg_match(
            '/([A-Z][a-z]{2})\s+(\d{1,2})\s*(?:\n|\r\n?).{0,80}?Check Out:/is',
            $text,
            $co
        ) === 1) {
            $year = $checkIn !== null ? substr($checkIn, 0, 4) : date('Y');
            $checkOut = sprintf('%s-%s-%02d', $year, $this->monthNum($co[1]), (int) $co[2]);
            $this->recordField(true);
        } else {
            $this->recordField(false);
        }

        return [$checkIn, $checkOut];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractCityState(string $text): array
    {
        // "1335 Avenue of the Americas, New York, NY, 10019 US"
        if (preg_match('/,\s*([A-Za-z .]+),\s*([A-Z]{2})\s*,?\s*\d{5}/', $text, $loc) === 1) {
            $this->recordField(true);
            return [trim($loc[1]), $loc[2]];
        }
        if (preg_match('/\n([A-Za-z .]+)\s+([A-Z]{2})\s+\d{5}/', $text, $loc) === 1) {
            $this->recordField(true);
            return [trim($loc[1]), $loc[2]];
        }
        $this->recordField(false);
        return [null, null];
    }
}
