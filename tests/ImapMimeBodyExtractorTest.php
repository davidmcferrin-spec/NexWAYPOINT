<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ImapMimeBodyExtractor;
use PHPUnit\Framework\TestCase;

final class ImapMimeBodyExtractorTest extends TestCase
{
    public function testProtonSignedForwardYieldsNestedPlain(): void
    {
        // Mirrors DreamHost/Proton: SIGNED → MIXED → ALTERNATIVE → PLAIN + RELATED/HTML
        $plainLeaf = (object) [
            'type' => 0,
            'subtype' => 'PLAIN',
            'encoding' => 4, // quoted-printable
        ];
        $htmlLeaf = (object) [
            'type' => 0,
            'subtype' => 'HTML',
            'encoding' => 3, // base64
        ];
        $related = (object) [
            'type' => 1,
            'subtype' => 'RELATED',
            'parts' => [$htmlLeaf],
        ];
        $alternative = (object) [
            'type' => 1,
            'subtype' => 'ALTERNATIVE',
            'parts' => [$plainLeaf, $related],
        ];
        $mixed = (object) [
            'type' => 1,
            'subtype' => 'MIXED',
            'parts' => [
                $alternative,
                (object) ['type' => 3, 'subtype' => 'PGP-KEYS', 'encoding' => 3],
            ],
        ];
        $signed = (object) [
            'type' => 1,
            'subtype' => 'SIGNED',
            'parts' => [
                $mixed,
                (object) ['type' => 3, 'subtype' => 'PGP-SIGNATURE', 'encoding' => 3],
            ],
        ];

        $bodies = [
            '1.1.1' => "From: American Airlines <no-reply@info.email.aa.com>\r\n"
                . "Confirmation code: AJXRPU\r\n"
                . "AA 3581\r\n",
            '1.1.2.1' => base64_encode('<p>American Airlines HTML</p>'),
        ];

        [$plain, $html] = ImapMimeBodyExtractor::extract(
            $signed,
            static fn (string $num): string => $bodies[$num] ?? ''
        );

        self::assertStringContainsString('American Airlines', $plain);
        self::assertStringContainsString('AJXRPU', $plain);
        self::assertStringContainsString('American Airlines HTML', $html);

        $detector = new EmailConfirmationDetector();
        $detected = $detector->detect(new EmailMessage(
            uid: '1',
            fromAddress: 'david.mcferrin@pm.me',
            subject: 'Fw: Your trip confirmation (DCA - HSV)',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $plain,
            bodyHtml: $html,
        ));
        self::assertSame('flight', $detected['type']);
        self::assertSame('aa.com', $detected['matched_domain']);
    }

    public function testSinglePartPlain(): void
    {
        $structure = (object) [
            'type' => 0,
            'subtype' => 'PLAIN',
            'encoding' => 0,
        ];
        [$plain, $html] = ImapMimeBodyExtractor::extract(
            $structure,
            static fn (string $num): string => $num === '1' ? 'Hilton Honors confirmation' : ''
        );
        self::assertSame('Hilton Honors confirmation', $plain);
        self::assertSame('', $html);
    }

    public function testAaPlainForwardParsesViaDetectorAndParser(): void
    {
        $plain = <<<'TXT'
------- Forwarded Message -------
From: American Airlines <no-reply@info.email.aa.com>
Subject: Your trip confirmation (DCA - HSV)

Confirmation code: AJXRPU
Thursday, July 16, 2026
DCA
Washington Reagan
4:47 PM
AA 3581
Operated by Envoy Air as American Eagle
HSV
Huntsville
5:57 PM
TXT;
        $detector = new EmailConfirmationDetector();
        $msg = new EmailMessage(
            uid: 'aa-fwd',
            fromAddress: 'david.mcferrin@pm.me',
            subject: 'Fw: Your trip confirmation (DCA - HSV)',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: $plain,
            bodyHtml: '',
        );
        $detected = $detector->detect($msg);
        self::assertSame('flight', $detected['type']);

        $parser = new \NexWaypoint\Mail\Parsers\AmericanAirlinesParser();
        $parsed = $parser->parse($msg);
        self::assertNotNull($parsed);
        self::assertSame('AJXRPU', $parsed['confirmation_code']);
        self::assertSame('DCA', $parsed['segments'][0]['origin']);
        self::assertSame('HSV', $parsed['segments'][0]['destination']);
    }
}
