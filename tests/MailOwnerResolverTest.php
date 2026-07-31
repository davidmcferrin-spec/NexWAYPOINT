<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\MailOwnerResolver;
use NexWaypoint\Users\UserRepository;

final class MailOwnerResolverTest extends NexWaypointTestCase
{
    public function testVendorFromMatchesDeliveredToHintInBody(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $user = $repo->create('dave', 'dave@work.example', 'test-password-12', 'Dave', 'subordinate', null);
        $repo->addEmail((int) $user->id, 'david.mcferrin@pm.me', 'Proton', (int) $user->id);

        $body = <<<'TXT'
Hilton
your reservation has been canceled.
Cancellation # 41373167
This email advertisement was delivered to
david.mcferrin@pm.me (
david.mcferrin@pm.me ). Click here to unsubscribe
TXT;

        $message = new EmailMessage(
            uid: '75',
            fromAddress: 'noreply@h6.hilton.com',
            subject: 'Your Aug-03-2026 Cancellation #41373167',
            receivedAt: new \DateTimeImmutable('2026-07-29'),
            bodyPlain: $body,
            bodyHtml: '',
        );

        $resolved = (new MailOwnerResolver($repo))->resolve($message);
        self::assertNotNull($resolved['user']);
        self::assertSame((int) $user->id, (int) $resolved['user']->id);
        self::assertSame('david.mcferrin@pm.me', $resolved['matched_email']);
        self::assertSame('vendor_recipient_fallback', $resolved['via']);
    }

    public function testVendorFromMatchesImapDeliveredToHeader(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $user = $repo->create('dave', 'dave@work.example', 'test-password-12', 'Dave', 'subordinate', null);
        $repo->addEmail((int) $user->id, 'david.mcferrin@pm.me', 'Proton', (int) $user->id);

        $message = new EmailMessage(
            uid: '77',
            fromAddress: 'no-reply@info.email.aa.com',
            subject: 'Your trip confirmation (HSV - DFW)',
            receivedAt: new \DateTimeImmutable('2026-07-31'),
            bodyPlain: 'American Airlines confirmation',
            bodyHtml: '',
            recipientAddresses: ['david.mcferrin@pm.me'],
        );

        $resolved = (new MailOwnerResolver($repo))->resolve($message);
        self::assertNotNull($resolved['user']);
        self::assertSame('david.mcferrin@pm.me', $resolved['matched_email']);
        self::assertSame('vendor_recipient_fallback', $resolved['via']);
    }

    public function testTeammateFromStillWinsOverRecipients(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $dave = $repo->create('dave', 'dave@example.com', 'test-password-12', 'Dave', 'subordinate', null);
        $other = $repo->create('other', 'other@example.com', 'test-password-12', 'Other', 'subordinate', null);

        $message = new EmailMessage(
            uid: 'fwd',
            fromAddress: 'dave@example.com',
            subject: 'Fwd: confirmation',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: 'Forwarded message',
            bodyHtml: '',
            recipientAddresses: ['other@example.com'],
        );

        $resolved = (new MailOwnerResolver($repo))->resolve($message);
        self::assertNotNull($resolved['user']);
        self::assertSame((int) $dave->id, (int) $resolved['user']->id);
        self::assertSame('from', $resolved['via']);
        self::assertNotSame((int) $other->id, (int) $resolved['user']->id);
    }

    public function testUnknownNonVendorFromDoesNotFallBack(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $repo->create('dave', 'dave@example.com', 'test-password-12', 'Dave', 'subordinate', null);

        $message = new EmailMessage(
            uid: 'stranger',
            fromAddress: 'stranger@random.example',
            subject: 'Hi',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: 'delivered to dave@example.com',
            bodyHtml: '',
            recipientAddresses: ['dave@example.com'],
        );

        $resolved = (new MailOwnerResolver($repo))->resolve($message);
        self::assertNull($resolved['user']);
        self::assertSame('none', $resolved['via']);
    }

    public function testRecipientsFromHeaderBlockPriority(): void
    {
        $raw = <<<'HDR'
Delivered-To: david.mcferrin@pm.me
X-Original-To: DAVID.MCFERRIN@pm.me
From: noreply@h6.hilton.com
To: someone-else@example.com
Cc: cc@example.com
HDR;
        $addrs = MailOwnerResolver::recipientsFromHeaderBlock($raw);
        self::assertSame('david.mcferrin@pm.me', $addrs[0]);
        self::assertContains('someone-else@example.com', $addrs);
        self::assertContains('cc@example.com', $addrs);
        // Deduped case variants of Delivered-To / X-Original-To
        self::assertSame(1, count(array_filter($addrs, static fn ($e) => $e === 'david.mcferrin@pm.me')));
    }

    public function testIsKnownVendorAddress(): void
    {
        self::assertTrue(EmailConfirmationDetector::isKnownVendorAddress('noreply@h6.hilton.com'));
        self::assertTrue(EmailConfirmationDetector::isKnownVendorAddress('no-reply@info.email.aa.com'));
        self::assertTrue(EmailConfirmationDetector::isKnownVendorAddress('etickets@amtrak.com'));
        self::assertFalse(EmailConfirmationDetector::isKnownVendorAddress('david.mcferrin@pm.me'));
    }
}
