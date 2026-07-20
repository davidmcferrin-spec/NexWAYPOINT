<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ForwardedMailNormalizer;
use NexWaypoint\Mail\Parsers\MarriottHotelParser;
use PHPUnit\Framework\TestCase;

final class MarriottHotelParserTest extends TestCase
{
    public function testProtonForwardedTributePortfolioConfirmation(): void
    {
        // Shape from Proton Fw: of Marriott/Tribute Reservation Confirmation #79852061
        // (Hotel Zachary, Chicago — Jun 24–26 2025).
        $raw = <<<'TXT'
Sent with Proton Mail secure email.

------- Forwarded Message -------
From: Tribute Reservations <reservations@res-marriott.com>
Date: On Thursday, May 29th, 2025 at 17:33
Subject: Reservation Confirmation #79852061 for Hotel Zachary, Chicago, a Tribute Portfolio Hotel
To: david.mcferrin@pm.me <david.mcferrin@pm.me>

> ENHANCE YOUR STAY
>
> Hotel Zachary, Chicago, a Tribute Portfolio Hotel
>
> 3630 North Clark Street Chicago, Illinois 60613 USA
>
> +1-773-302-2300
>
> Thank you for booking with us, Mr. David Mcferrin.
>
> Tue, Jun 24, 2025 – Thu, Jun 26, 2025
>
> Confirmation Number: 79852061
>
> Check-In:
>
> Tuesday, June 24, 2025
>
> 04:00 PM
>
> Check-Out:
>
> Thursday, June 26, 2025
>
> 11:00 AM
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '7',
            fromAddress: 'david.mcferrin@pm.me',
            subject: 'Fw: Reservation Confirmation #79852061 for Hotel Zachary, Chicago, a Tribute Portfolio Hotel',
            receivedAt: new \DateTimeImmutable('2026-07-20'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        $detected = (new EmailConfirmationDetector())->detect($normalized);
        self::assertSame('hotel', $detected['type']);
        self::assertSame('marriott.com', $detected['matched_domain']);

        $parser = new MarriottHotelParser();
        $parsed = $parser->parse($normalized);
        self::assertNotNull($parsed);
        self::assertSame('confirm', $parsed['event']);
        self::assertSame('79852061', $parsed['confirmation_code']);
        self::assertStringContainsString('Hotel Zachary', (string) $parsed['property_name']);
        self::assertSame('2025-06-24', $parsed['check_in']);
        self::assertSame('2025-06-26', $parsed['check_out']);
        self::assertSame('Chicago', $parsed['city']);
        self::assertSame('IL', $parsed['state_region']);
        self::assertGreaterThanOrEqual(0.75, $parser->confidenceScore());
    }
}
