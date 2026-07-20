<?php

declare(strict_types=1);

namespace NexWaypoint\Mail\Parsers;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ParserBase;

/**
 * Brand-agnostic hotel confirmation parser. Deliberately generic rather
 * than one-parser-per-brand (Marriott/Hilton/IHG/Hyatt/Choice) -- that's
 * the natural next iteration once real confirmation emails are available
 * to build brand-specific fixtures from. This parser covers the common
 * phrasing patterns shared across most hotel confirmation templates and
 * is what MailPoller uses today for any email EmailConfirmationDetector
 * classifies as 'hotel'.
 */
final class GenericHotelConfirmationParser extends ParserBase
{
    public function parse(EmailMessage $message): ?array
    {
        $this->resetConfidenceTracking();
        $text = $message->bestText();
        $subject = $message->subject;

        $confirmationCode = $this->extractFirstMatch([
            '/confirmation\s*(?:number|#|no\.?)?\s*[:\-]?\s*([A-Z0-9]{5,12})/i',
            '/reservation\s*(?:number|#|no\.?)?\s*[:\-]?\s*([A-Z0-9]{5,12})/i',
        ], $text);

        $propertyName = $this->extractFirstMatch([
            '/(?:reservation|stay|booking)\s+at\s+(.+?)(?:\s+is\s+confirmed|\s+on\s+|\s*[\r\n]|$)/i',
            '/your\s+(?:stay|hotel)\s*[:\-]\s*(.+?)(?:[\r\n]|$)/i',
        ], $subject . "\n" . $text);

        $checkInRaw = $this->extractFirstMatch([
            '/check[-\s]?in[:\s]*(?:date)?[:\s]*([A-Za-z]+ \d{1,2},? \d{4}|\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i',
            '/arrival[:\s]*([A-Za-z]+ \d{1,2},? \d{4}|\d{1,2}\/\d{1,2}\/\d{2,4})/i',
        ], $text);
        $checkIn = $this->parseFlexibleDate($checkInRaw);

        $checkOutRaw = $this->extractFirstMatch([
            '/check[-\s]?out[:\s]*(?:date)?[:\s]*([A-Za-z]+ \d{1,2},? \d{4}|\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i',
            '/departure[:\s]*([A-Za-z]+ \d{1,2},? \d{4}|\d{1,2}\/\d{1,2}\/\d{2,4})/i',
        ], $text);
        $checkOut = $this->parseFlexibleDate($checkOutRaw);

        $address = $this->extractPattern(
            '/(\d{1,5}\s[A-Za-z0-9.\s]{2,40},\s*[A-Za-z\s]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)/',
            $text
        );

        $roomType = $this->extractFirstMatch([
            '/room\s*type[:\s]*([A-Za-z0-9\s\-]{3,40})(?:[\r\n]|$)/i',
            '/room[:\s]+([A-Za-z0-9\s\-]{3,40}(?:room|suite|king|queen|double))/i',
        ], $text);

        $passengerName = $this->extractFirstMatch([
            '/guest\s*name[:\s]*([A-Za-z\s.\'-]{3,60})(?:[\r\n]|$)/i',
        ], $text);

        // A hotel confirmation with neither a confirmation code nor a
        // property name isn't usable -- treat as no match at all, distinct
        // from a low-confidence-but-partial extraction.
        if ($confirmationCode === null && $propertyName === null) {
            return null;
        }

        return [
            'kind' => 'hotel',
            'event' => 'confirm',
            'confirmation_code' => $confirmationCode,
            'property_name' => $propertyName,
            'brand' => null,
            'address' => $address,
            'city' => null,
            'state_region' => null,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_type' => $roomType,
            'passenger_name' => $passengerName,
        ];
    }
}
