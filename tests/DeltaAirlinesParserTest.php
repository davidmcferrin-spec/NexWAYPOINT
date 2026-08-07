<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\Parsers\DeltaAirlinesParser;
use PHPUnit\Framework\TestCase;

/**
 * Delta Flight Receipt / change / cancel — layout from live Proton forwards
 * (parse-92 style). Check-in must stay ignored.
 */
final class DeltaAirlinesParserTest extends TestCase
{
    private function message(string $subject, string $plain, string $from = 'david@example.com'): EmailMessage
    {
        return new EmailMessage(
            uid: 'delta-1',
            fromAddress: $from,
            subject: $subject,
            receivedAt: new \DateTimeImmutable('2026-08-06'),
            bodyPlain: $plain,
            bodyHtml: '',
        );
    }

    /** Dequoted itinerary body matching parse-92 Flight Receipt structure. */
    private function receiptBody(): string
    {
        return <<<'TXT'
From: Delta Air Lines <DeltaAirLines@t.delta.com>
Subject: Your Flight Receipt - DAVID M MCFERRIN 10AUG26

Confirmation Number

H7JU8N

You're all set. If your plans change, you can make adjustments or cancel your itinerary.

Passenger Info
Name: DAVID M MCFERRIN

FLIGHT
SEAT
DELTA 5210
05A
DELTA 5040
05A

Mon, 10AUG

DEPART
ARRIVE

DELTA 5210*
Delta Comfort Classic (S)

HUNTSVILLE
07:10AM

NYC-LAGUARDIA
10:33AM

Wed, 12AUG

DEPART
ARRIVE

DELTA 5040*
Delta Comfort Classic (S)

NYC-LAGUARDIA
08:10PM

HUNTSVILLE
09:55PM

*DL5210 is operated by Endeavor Air DBA Delta Connection
*DL5040 is operated by Endeavor Air DBA Delta Connection

Flight Receipt
Ticket #: 0062453064512
Issue Date: 06AUG26

Mon 10 Aug 2026
HSV-LGA
Wed 12 Aug 2026
LGA-HSV
TXT;
    }

    public function testFlightReceiptConfirmRoundTrip(): void
    {
        $parser = new DeltaAirlinesParser();
        $result = $parser->parse($this->message(
            'Fw: Your Flight Receipt - DAVID M MCFERRIN 10AUG26',
            $this->receiptBody(),
        ));

        self::assertNotNull($result);
        self::assertSame('flight', $result['kind']);
        self::assertSame('confirm', $result['event']);
        self::assertSame('H7JU8N', $result['confirmation_code']);
        self::assertCount(2, $result['segments']);

        $out = $result['segments'][0];
        self::assertSame('5210', $out['flight_number']);
        self::assertSame('HSV', $out['origin']);
        self::assertSame('LGA', $out['destination']);
        self::assertSame('2026-08-10 07:10:00', $out['depart_dt']);
        self::assertSame('2026-08-10 10:33:00', $out['arrive_dt']);

        $ret = $result['segments'][1];
        self::assertSame('5040', $ret['flight_number']);
        self::assertSame('LGA', $ret['origin']);
        self::assertSame('HSV', $ret['destination']);
        self::assertSame('2026-08-12 20:10:00', $ret['depart_dt']);
        self::assertSame('2026-08-12 21:55:00', $ret['arrive_dt']);
    }

    public function testFlightReceiptChangeSubject(): void
    {
        $parser = new DeltaAirlinesParser();
        $result = $parser->parse($this->message(
            'Updated itinerary - Your Flight Receipt H7JU8N',
            $this->receiptBody(),
        ));

        self::assertNotNull($result);
        self::assertSame('change', $result['event']);
        self::assertSame('H7JU8N', $result['confirmation_code']);
        self::assertCount(2, $result['segments']);
    }

    public function testCancelWithConfirmationNumber(): void
    {
        $parser = new DeltaAirlinesParser();
        $result = $parser->parse($this->message(
            'Your Delta trip has been cancelled',
            "Confirmation Number\n\nH7JU8N\n\nYour itinerary was cancelled.",
        ));

        self::assertNotNull($result);
        self::assertSame('cancel', $result['event']);
        self::assertSame('H7JU8N', $result['confirmation_code']);
        self::assertSame([], $result['segments']);
    }

    public function testCheckInIsIgnored(): void
    {
        $parser = new DeltaAirlinesParser();
        $result = $parser->parse($this->message(
            'Check-in is open for your Delta flight',
            "Confirmation Number\n\nH7JU8N\n\nDELTA 5210\nHUNTSVILLE\n07:10AM\nNYC-LAGUARDIA\n10:33AM",
        ));

        self::assertNotNull($result);
        self::assertSame('ignore', $result['event']);
    }

    public function testDetectorRoutesFlightReceiptAsConfirm(): void
    {
        $msg = $this->message(
            'Fw: Your Flight Receipt - DAVID M MCFERRIN 10AUG26',
            $this->receiptBody(),
        );
        $detection = (new EmailConfirmationDetector())->detect($msg);
        self::assertSame('flight', $detection['type']);
        self::assertSame('confirm', $detection['event']);
        self::assertSame('delta.com', $detection['matched_domain']);
    }

    public function testClassicSameLineReceiptStillWorks(): void
    {
        $body = <<<'TXT'
Confirmation Number: ABCDEF

DELTA 3026
HUNTSVILLE 05:12PM
ATLANTA 07:16PM

Mon, 15SEP26
TXT;
        // Date header must precede the flight for per-leg dating; put it first.
        $body = "Mon, 15SEP26\n\nConfirmation Number: ABCDEF\n\nDELTA 3026\nHUNTSVILLE 05:12PM\nATLANTA 07:16PM\n";

        $parser = new DeltaAirlinesParser();
        $result = $parser->parse($this->message('Your Flight Receipt', $body));

        self::assertNotNull($result);
        self::assertSame('confirm', $result['event']);
        self::assertCount(1, $result['segments']);
        self::assertSame('HSV', $result['segments'][0]['origin']);
        self::assertSame('ATL', $result['segments'][0]['destination']);
        self::assertSame('2026-09-15 17:12:00', $result['segments'][0]['depart_dt']);
    }
}
