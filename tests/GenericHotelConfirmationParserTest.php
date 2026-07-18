<?php

declare(strict_types=1);

namespace NexWaypont\Tests;

use NexWaypont\Mail\EmailMessage;
use NexWaypont\Mail\Parsers\GenericHotelConfirmationParser;
use PHPUnit\Framework\TestCase;

/**
 * All fixtures below are synthetic (hand-written to mimic common phrasing
 * patterns) -- no real confirmation email content is used or stored.
 */
final class GenericHotelConfirmationParserTest extends TestCase
{
    private function message(string $subject, string $body): EmailMessage
    {
        return new EmailMessage(
            uid: '1',
            fromAddress: 'reservations@example-hotel.com',
            subject: $subject,
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $body,
            bodyHtml: '',
        );
    }

    public function testParsesStandardConfirmationLayout(): void
    {
        $parser = new GenericHotelConfirmationParser();
        $msg = $this->message(
            'Your reservation at Lakeside Grand Hotel is confirmed',
            "Confirmation Number: AB12345\n" .
            "Check-in: Aug 1, 2026\n" .
            "Check-out: Aug 3, 2026\n" .
            "123 Lake Shore Dr, Chicago, IL 60601\n" .
            "Room Type: King Suite\n"
        );

        $result = $parser->parse($msg);

        self::assertNotNull($result);
        self::assertSame('AB12345', $result['confirmation_code']);
        self::assertSame('Lakeside Grand Hotel', $result['property_name']);
        self::assertSame('2026-08-01', $result['check_in']);
        self::assertSame('2026-08-03', $result['check_out']);
        self::assertGreaterThanOrEqual(0.75, $parser->confidenceScore());
    }

    public function testParsesSlashDateFormat(): void
    {
        $parser = new GenericHotelConfirmationParser();
        $msg = $this->message(
            'Your stay: Riverside Inn',
            "Reservation Number: XY98765\n" .
            "Check-in Date: 08/01/2026\n" .
            "Check-out Date: 08/03/2026\n"
        );

        $result = $parser->parse($msg);

        self::assertNotNull($result);
        self::assertSame('XY98765', $result['confirmation_code']);
        self::assertSame('2026-08-01', $result['check_in']);
        self::assertSame('2026-08-03', $result['check_out']);
    }

    public function testParsesArrivalDepartureWording(): void
    {
        $parser = new GenericHotelConfirmationParser();
        $msg = $this->message(
            'Booking at Mountain View Lodge is confirmed',
            "Confirmation #: MV55501\n" .
            "Arrival: September 10, 2026\n" .
            "Departure: September 12, 2026\n" .
            "Guest Name: David Mcferrin\n"
        );

        $result = $parser->parse($msg);

        self::assertNotNull($result);
        self::assertSame('MV55501', $result['confirmation_code']);
        self::assertSame('David Mcferrin', $result['passenger_name']);
        self::assertSame('2026-09-10', $result['check_in']);
        self::assertSame('2026-09-12', $result['check_out']);
    }

    public function testReturnsNullWhenNoConfirmationOrPropertyFound(): void
    {
        $parser = new GenericHotelConfirmationParser();
        $msg = $this->message(
            'Newsletter: Fall travel tips',
            "Here are some tips for your next trip. Thanks for subscribing!"
        );

        $result = $parser->parse($msg);

        self::assertNull($result);
    }

    public function testLowConfidenceWhenOnlyPartialDataPresent(): void
    {
        $parser = new GenericHotelConfirmationParser();
        $msg = $this->message(
            'Your reservation at Budget Stay Motel is confirmed',
            "Thanks for booking with us! We'll see you soon."
            // No confirmation code, no dates, no address.
        );

        $result = $parser->parse($msg);

        self::assertNotNull($result); // property_name alone is enough to not be a hard null...
        self::assertLessThan(0.75, $parser->confidenceScore()); // ...but confidence should reflect the gaps.
    }
}
