<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ForwardedMailNormalizer;
use NexWaypoint\Mail\Parsers\HiltonHotelParser;
use PHPUnit\Framework\TestCase;

final class HiltonHotelParserTest extends TestCase
{
    public function testProtonForwardedMidtownConfirmation(): void
    {
        // Shape from a real Proton Fw: of Hilton confirmation #3500303313
        // (New York Hilton Midtown, Jul 7–13 2026).
        $raw = <<<'TXT'
Sent with Proton Mail secure email.

------- Forwarded Message -------
From: Hilton Hotels & Resorts Confirmed <noreply@h6.hilton.com>
Date: On Tuesday, July 7th, 2026 at 11:34
Subject: Your Jul-07-2026 Confirmation #3500303313
To: david.mcferrin@pm.me <david.mcferrin@pm.me>

> Hello DAVID,
>
> Your reservation for Tuesday Jul 07, 2026 has been confirmed.
>
> Confirmation # 3500303313
>
> New York Hilton Midtown
> -----------------------
>
> ​1335 Avenue of the Americas, New York, NY, 10019 US ​
>
> +12125867000
>
> ​Tuesday​
>
> ​Jul​ 07
>
> Check In: ​ 3:00 PM​
>
> ### 6
>
> Nights
>
> ​Monday​
>
> ​Jul​ 13
>
> Check Out: ​12:00 PM​
>
> Jul-07-2026 - Jul-09-2026
> Jul-09-2026 - Jul-10-2026
> Jul-10-2026 - Jul-11-2026
> Jul-11-2026 - Jul-12-2026
> Jul-12-2026 - Jul-13-2026
TXT;

        $normalized = ForwardedMailNormalizer::normalize(new EmailMessage(
            uid: '6',
            fromAddress: 'david.mcferrin@pm.me',
            subject: 'Fw: Your Jul-07-2026 Confirmation #3500303313',
            receivedAt: new \DateTimeImmutable('2026-07-20'),
            bodyPlain: $raw,
            bodyHtml: '',
        ));

        $detected = (new EmailConfirmationDetector())->detect($normalized);
        self::assertSame('hotel', $detected['type']);
        self::assertSame('hilton.com', $detected['matched_domain']);

        $parsed = (new HiltonHotelParser())->parse($normalized);
        self::assertNotNull($parsed);
        self::assertSame('confirm', $parsed['event']);
        self::assertSame('3500303313', $parsed['confirmation_code']);
        self::assertSame('New York Hilton Midtown', $parsed['property_name']);
        self::assertSame('2026-07-07', $parsed['check_in']);
        self::assertSame('2026-07-13', $parsed['check_out']);
        self::assertSame('New York', $parsed['city']);
        self::assertSame('NY', $parsed['state_region']);

        $scored = new HiltonHotelParser();
        $scored->parse($normalized);
        self::assertGreaterThanOrEqual(0.75, $scored->confidenceScore());
    }
}
