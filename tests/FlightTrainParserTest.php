<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\Parsers\AmericanAirlinesParser;
use NexWaypoint\Mail\Parsers\AmtrakParser;
use NexWaypoint\Mail\Parsers\BreezeAirlinesParser;
use NexWaypoint\Mail\Parsers\UnitedAirlinesParser;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic fixtures only — no real confirmation email content.
 */
final class FlightTrainParserTest extends TestCase
{
    private function message(string $from, string $subject, string $plain, string $html = ''): EmailMessage
    {
        return new EmailMessage(
            uid: 't1',
            fromAddress: $from,
            subject: $subject,
            receivedAt: new \DateTimeImmutable('2026-07-01'),
            bodyPlain: $plain,
            bodyHtml: $html,
        );
    }

    public function testAmericanAirlinesJsonLdConfirm(): void
    {
        $html = <<<'HTML'
<html><head>
<script type="application/ld+json">
{
  "@type": "FlightReservation",
  "reservationNumber": "ABC123",
  "reservationStatus": "http://schema.org/ReservationConfirmed",
  "reservationFor": {
    "@type": "Flight",
    "flightNumber": "AA 100",
    "airline": {"@type": "Airline", "iataCode": "AA", "name": "American Airlines"},
    "departureAirport": {"@type": "Airport", "iataCode": "ORD"},
    "arrivalAirport": {"@type": "Airport", "iataCode": "DFW"},
    "departureTime": "2026-09-01T10:00:00Z",
    "arrivalTime": "2026-09-01T12:30:00Z"
  }
}
</script>
</head><body>Confirmation code: ABC123</body></html>
HTML;

        $parser = new AmericanAirlinesParser();
        $result = $parser->parse($this->message(
            'no-reply@info.email.aa.com',
            'Your trip confirmation',
            '',
            $html
        ));

        self::assertNotNull($result);
        self::assertSame('flight', $result['kind']);
        self::assertSame('confirm', $result['event']);
        self::assertSame('ABC123', $result['confirmation_code']);
        self::assertCount(1, $result['segments']);
        self::assertSame('ORD', $result['segments'][0]['origin']);
        self::assertSame('DFW', $result['segments'][0]['destination']);
        self::assertSame('2026-09-01 10:00:00', $result['segments'][0]['depart_dt']);
    }

    public function testAmericanAirlinesCancel(): void
    {
        $parser = new AmericanAirlinesParser();
        $result = $parser->parse($this->message(
            'no-reply@info.email.aa.com',
            'Your trip has been cancelled',
            "Confirmation code: XYZ789\nYour ticket was cancelled."
        ));

        self::assertNotNull($result);
        self::assertSame('cancel', $result['event']);
        self::assertSame('XYZ789', $result['confirmation_code']);
    }

    public function testBreezeConfirm(): void
    {
        $parser = new BreezeAirlinesParser();
        $result = $parser->parse($this->message(
            'noreply@flybreeze.com',
            'Your Breeze confirmation QWERTY',
            "Confirmation Number: QWERTY\nFlight MX 123\nLas Vegas (LAS)\nHuntsville (HSV)\nSeptember 10, 2026\n8:00 am\n11:00 am\n"
        ));

        self::assertNotNull($result);
        self::assertSame('confirm', $result['event']);
        self::assertSame('QWERTY', $result['confirmation_code']);
        self::assertSame('LAS', $result['segments'][0]['origin']);
        self::assertSame('HSV', $result['segments'][0]['destination']);
        self::assertSame('MX', $result['segments'][0]['carrier_iata']);
    }

    public function testAmtrakConfirm(): void
    {
        $parser = new AmtrakParser();
        $result = $parser->parse($this->message(
            'eTicket@amtrak.com',
            'Your Amtrak eTicket',
            "Reservation Number - A1B2C\nTRAIN 90: New York, NY - Moynihan to Washington, DC - Union Station (One-Way)\nDepart 7:00 AM, Monday, April 6, 2026\n"
        ));

        self::assertNotNull($result);
        self::assertSame('train', $result['kind']);
        self::assertSame('A1B2C', $result['confirmation_code']);
        self::assertSame('90', $result['segments'][0]['flight_number']);
        self::assertSame('train', $result['segments'][0]['segment_type']);
    }

    public function testUnitedMultiLegConfirm(): void
    {
        $plain = <<<'TXT'
Confirmation Number: UA9X2K

Flight 1 of 2 UA 4821
Huntsville (HSV)
Denver (DEN)
Mon, Aug 10, 2026
8:05 AM
10:20 AM

Flight 2 of 2 UA 1630
Denver (DEN)
Los Angeles (LAX)
Mon, Aug 10, 2026
12:15 PM
1:45 PM
TXT;

        $parser = new UnitedAirlinesParser();
        $result = $parser->parse($this->message(
            'united@united.com',
            'Your United Airlines Confirmation UA9X2K',
            $plain
        ));

        self::assertNotNull($result);
        self::assertSame('confirm', $result['event']);
        self::assertSame('UA9X2K', $result['confirmation_code']);
        self::assertCount(2, $result['segments']);
        self::assertSame('HSV', $result['segments'][0]['origin']);
        self::assertSame('DEN', $result['segments'][0]['destination']);
        self::assertSame('4821', $result['segments'][0]['flight_number']);
        self::assertSame('DEN', $result['segments'][1]['origin']);
        self::assertSame('LAX', $result['segments'][1]['destination']);
        self::assertSame('2026-08-10 08:05:00', $result['segments'][0]['depart_dt']);
        self::assertSame('2026-08-10 12:15:00', $result['segments'][1]['depart_dt']);
    }

    public function testUnitedRoundTripTwoLegs(): void
    {
        $plain = <<<'TXT'
Confirmation Number: RTURN1

Flight 1 of 2 UA 100
Huntsville (HSV)
Denver (DEN)
Tue, Sep 1, 2026
7:00 AM
9:00 AM

Flight 2 of 2 UA 200
Denver (DEN)
Huntsville (HSV)
Fri, Sep 4, 2026
5:00 PM
8:00 PM
TXT;

        $parser = new UnitedAirlinesParser();
        $result = $parser->parse($this->message(
            'united@united.com',
            'Confirmation Number: RTURN1',
            $plain
        ));

        self::assertNotNull($result);
        self::assertCount(2, $result['segments']);
        self::assertSame('HSV', $result['segments'][0]['origin']);
        self::assertSame('HSV', $result['segments'][1]['destination']);
    }
}
