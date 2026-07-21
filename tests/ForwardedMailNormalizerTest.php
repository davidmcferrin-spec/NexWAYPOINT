<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ForwardedMailNormalizer;
use NexWaypoint\Mail\Parsers\AmericanAirlinesParser;
use PHPUnit\Framework\TestCase;

final class ForwardedMailNormalizerTest extends TestCase
{
    public function testProtonForwardDequotesAndUsesOriginalSubject(): void
    {
        $raw = <<<'TXT'
Sent with Proton Mail secure email.

------- Forwarded Message -------
From: American Airlines <no-reply@info.email.aa.com>
Date: On Sunday, July 12th, 2026 at 13:29
Subject: Your trip confirmation (DCA - HSV)
To: DAVID.MCFERRIN@PM.ME <DAVID.MCFERRIN@PM.ME>

> Confirmation code: AJXRPU
> =
> AA 3581
> DCA
> HSV
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '1',
            fromAddress: 'david.mcferrin@pm.me',
            subject: 'Fw: Your trip confirmation (DCA - HSV)',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        self::assertSame('david.mcferrin@pm.me', $normalized->fromAddress);
        self::assertSame('Your trip confirmation (DCA - HSV)', $normalized->subject);
        self::assertStringContainsString('Confirmation code: AJXRPU', $normalized->bodyPlain);
        self::assertStringNotContainsString('> Confirmation', $normalized->bodyPlain);
        self::assertStringContainsString('American Airlines', $normalized->bodyPlain);
        self::assertStringNotContainsString('Date:', $normalized->bodyPlain);
        self::assertStringNotContainsString('July 12th, 2026', $normalized->bodyPlain);

        $detected = (new EmailConfirmationDetector())->detect($normalized);
        self::assertSame('flight', $detected['type']);
        self::assertSame('aa.com', $detected['matched_domain']);

        $parsed = (new AmericanAirlinesParser())->parse($normalized);
        self::assertNotNull($parsed);
        self::assertSame('AJXRPU', $parsed['confirmation_code']);
    }

    public function testGmailForwardMarker(): void
    {
        $raw = <<<'TXT'
FYI

---------- Forwarded message ---------
From: Hilton Honors <hiltonhonors@hilton.com>
Date: Mon, Jul 1, 2026 at 10:00 AM
Subject: Your Jul-04-2026 Confirmation
To: <dave@example.com>

Confirmation # 321654987
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '2',
            fromAddress: 'dave@example.com',
            subject: 'Fwd: Your Jul-04-2026 Confirmation',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        self::assertSame('Your Jul-04-2026 Confirmation', $normalized->subject);
        self::assertStringNotContainsString('Date:', $normalized->bodyPlain);
        self::assertStringNotContainsString('Jul 1, 2026 at 10:00 AM', $normalized->bodyPlain);
        $detected = (new EmailConfirmationDetector())->detect($normalized);
        self::assertSame('hotel', $detected['type']);
        self::assertSame('hilton.com', $detected['matched_domain']);
    }

    public function testOutlookOriginalMessageMarker(): void
    {
        $raw = <<<'TXT'
See below.

-----Original Message-----
From: Delta Air Lines <delta@delta.com>
Sent: Monday, July 1, 2026 9:00 AM
To: dave@example.com
Subject: Your Trip Confirmation # ABC123

FLIGHT CONFIRMATION # ABC123
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '3',
            fromAddress: 'dave@example.com',
            subject: 'FW: Your Trip Confirmation # ABC123',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        self::assertSame('Your Trip Confirmation # ABC123', $normalized->subject);
        self::assertStringNotContainsString('Sent:', $normalized->bodyPlain);
        self::assertStringNotContainsString('July 1, 2026 9:00 AM', $normalized->bodyPlain);
        $detected = (new EmailConfirmationDetector())->detect($normalized);
        self::assertSame('flight', $detected['type']);
        self::assertSame('delta.com', $detected['matched_domain']);
    }

    public function testYahooForwardMarker(): void
    {
        $raw = <<<'TXT'
----- Forwarded Message -----
From: United Airlines <united@united.com>
To: "dave@example.com" <dave@example.com>
Subject: Confirmation Number: XYZ789

Confirmation Number: XYZ789
UA 123
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '4',
            fromAddress: 'dave@example.com',
            subject: 'Fw: Confirmation Number: XYZ789',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        $detected = (new EmailConfirmationDetector())->detect($normalized);
        self::assertSame('flight', $detected['type']);
        self::assertSame('united.com', $detected['matched_domain']);
    }

    public function testAppleBeginForwardedMessage(): void
    {
        $raw = <<<'TXT'
Begin forwarded message:

From: Marriott Bonvoy <noreply@marriott.com>
Subject: Reservation Confirmed
Date: July 1, 2026
To: dave@example.com

Your reservation is confirmed.
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '5',
            fromAddress: 'dave@example.com',
            subject: 'Fwd: Reservation Confirmed',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        $detected = (new EmailConfirmationDetector())->detect($normalized);
        self::assertSame('hotel', $detected['type']);
        self::assertSame('marriott.com', $detected['matched_domain']);
    }

    public function testDirectAirlineMailUnchangedForOwnership(): void
    {
        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '6',
            fromAddress: 'no-reply@info.email.aa.com',
            subject: 'Your trip confirmation',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: "Confirmation code: ABCDEF\nAA 100\nORD\nDFW",
            bodyHtml: '',
        ));

        self::assertSame('no-reply@info.email.aa.com', $normalized->fromAddress);
        self::assertSame('Your trip confirmation', $normalized->subject);
    }

    /**
     * Forward header Date must not become the flight depart date when the
     * itinerary date appears later in the body.
     */
    public function testUnitedForwardDoesNotUseHeaderDateForDepart(): void
    {
        $raw = <<<'TXT'
FYI

---------- Forwarded message ---------
From: United Airlines <united@united.com>
Date: Mon, Jul 1, 2026 at 10:00 AM
Subject: Confirmation Number: UA9XYZ
To: <dave@example.com>

Confirmation Number: UA9XYZ
UA 482
Huntsville (HSV) to Denver (DEN)
Departing Wednesday, July 29, 2026 8:15 AM
Arriving Wednesday, July 29, 2026 10:05 AM
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '7',
            fromAddress: 'dave@example.com',
            subject: 'Fwd: Confirmation Number: UA9XYZ',
            receivedAt: new \DateTimeImmutable('2026-07-01 15:00:00'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        self::assertStringNotContainsString('Jul 1, 2026 at 10:00 AM', $normalized->bodyPlain);

        $parsed = (new \NexWaypoint\Mail\Parsers\UnitedAirlinesParser())->parse($normalized);
        self::assertNotNull($parsed);
        self::assertNotEmpty($parsed['segments'] ?? []);
        $depart = (string) ($parsed['segments'][0]['depart_dt'] ?? '');
        self::assertStringStartsWith('2026-07-29', $depart);
        self::assertStringNotContainsString('2026-07-01', $depart);
    }
}
