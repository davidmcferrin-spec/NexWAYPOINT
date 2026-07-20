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
        $subjectLower = strtolower($subject);

        if (str_contains($subjectLower, 'enjoyed your stay') || str_contains($subjectLower, 'come again soon')) {
            return ['kind' => 'hotel', 'event' => 'ignore', 'confirmation_code' => null];
        }

        if (str_contains($subjectLower, 'cancellation') || str_contains($subjectLower, 'canceled')
            || str_contains($subjectLower, 'cancelled')) {
            $code = $this->extractFirstMatch([
                '/Cancellation\s*#\s*([0-9]{6,12})/i',
                '/Cancellation\s*#\s*([A-Z0-9]{6,12})/i',
            ], $subject . "\n" . $text);
            // Hilton cancel emails use a cancellation #, not always the original confirmation.
            // Also try to keep hotel name for logging.
            $property = $this->extractFirstMatch([
                '/\n([^\n]+Hilton[^\n]+)\n/i',
                '/The\s+([^\n]+)\n\d/i',
            ], $text);
            $checkIn = null;
            $checkOut = null;
            if (preg_match('/([A-Z][a-z]{2})-(\d{2})-(\d{4})\s*-\s*([A-Z][a-z]{2})-(\d{2})-(\d{4})/i', $text, $drm)) {
                $checkIn = sprintf('%s-%s-%s', $drm[3], $this->monthNum($drm[1]), $drm[2]);
                $checkOut = sprintf('%s-%s-%s', $drm[6], $this->monthNum($drm[4]), $drm[5]);
                $this->recordField(true);
            }
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
        ], $subject . "\n" . $text);

        $property = $this->extractFirstMatch([
            '/\n(Hilton[^\n]+)\n/i',
            '/\n(Embassy Suites[^\n]+)\n/i',
            '/\n(Signia by Hilton[^\n]+)\n/i',
            '/\n(Tapestry Collection[^\n]+)\n/i',
            '/\n(Homewood Suites[^\n]+)\n/i',
            '/\n(Hampton[^\n]+)\n/i',
            '/Your reservation for[^\n]+\n([^\n]+)\n/i',
        ], $text);

        // Check-in / out with Hilton's split date + time lines.
        $checkInDate = null;
        $checkOutDate = null;
        if (preg_match('/Check In:.*?(\d{1,2}:\d{2}\s*[AP]M)/is', $text, $ciTime)
            && preg_match('/([A-Z][a-z]{2}).{0,5}(\d{1,2}).{0,20}Check In:/is', $text, $ciDate)
        ) {
            // Prefer "Your reservation for Jul-01-2026"
            if (preg_match('/reservation for\s+([A-Z][a-z]{2})-(\d{2})-(\d{4})/i', $text, $rd)) {
                $checkInDate = sprintf('%s-%s-%s', $rd[3], $this->monthNum($rd[1]), $rd[2]);
            }
        }
        if (preg_match('/reservation for\s+([A-Z][a-z]{2})-(\d{2})-(\d{4})/i', $subject . ' ' . $text, $rd)) {
            $checkInDate = sprintf('%s-%s-%s', $rd[3], $this->monthNum($rd[1]), $rd[2]);
            $this->recordField(true);
        } else {
            $this->recordField(false);
        }

        // Checkout from "Jul 05" near Check Out or night rows "04-Jul-2026 - 05-Jul-2026"
        if (preg_match_all('/(\d{2})-([A-Z][a-z]{2})-(\d{4})\s*-\s*(\d{2})-([A-Z][a-z]{2})-(\d{4})/i', $text, $nights, PREG_SET_ORDER)) {
            $first = $nights[0];
            $last = $nights[count($nights) - 1];
            if ($checkInDate === null) {
                $checkInDate = sprintf('%s-%s-%s', $first[3], $this->monthNum($first[2]), $first[1]);
            }
            $checkOutDate = sprintf('%s-%s-%s', $last[6], $this->monthNum($last[5]), $last[4]);
            $this->recordField(true);
        } elseif (preg_match('/Check Out:.*?(\d{1,2}:\d{2}\s*[AP]M)/is', $text)
            && preg_match_all('/([A-Z][a-z]{2}).{0,6}(\d{1,2})/u', $text, $dm)
        ) {
            $this->recordField(false);
        } else {
            $this->recordField($checkOutDate !== null);
        }

        // Subject often has arrival date: Your Jul-01-2026 Confirmation
        if ($checkInDate === null && preg_match('/Your\s+([A-Z][a-z]{2})-(\d{2})-(\d{4})\s+Confirmation/i', $subject, $sm)) {
            $checkInDate = sprintf('%s-%s-%s', $sm[3], $this->monthNum($sm[1]), $sm[2]);
            $this->recordField(true);
        }

        // City from address line under hotel name
        $city = null;
        $state = null;
        if (preg_match('/\n([A-Za-z .]+)\s+([A-Z]{2})\s+\d{5}/', $text, $loc)) {
            $city = trim($loc[1]);
            $state = $loc[2];
            $this->recordField(true);
        } else {
            $this->recordField(false);
        }

        if ($code === null || $property === null || $checkInDate === null) {
            return null;
        }
        // If no checkout, assume 1 night.
        if ($checkOutDate === null) {
            $checkOutDate = date('Y-m-d', strtotime($checkInDate . ' +1 day'));
            $this->recordField(true);
        }

        return [
            'kind' => 'hotel',
            'event' => 'confirm',
            'confirmation_code' => $code,
            'property_name' => trim($property),
            'brand' => 'Hilton',
            'address' => null,
            'city' => $city,
            'state_region' => $state,
            'check_in' => $checkInDate,
            'check_out' => $checkOutDate,
            'room_type' => null,
        ];
    }
}
