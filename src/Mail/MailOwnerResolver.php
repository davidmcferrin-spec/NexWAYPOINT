<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Users\User;
use NexWaypoint\Users\UserRepository;

/**
 * Attribute inbound mail to a NexWAYPOINT user.
 *
 * Primary: outer From: (teammate forward).
 * Fallback when From is a known vendor (or unmatched): Delivered-To / To /
 * X-Original-To / Cc, then body "delivered to" / "sent to" recipient clues.
 */
final class MailOwnerResolver
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return array{user: ?User, matched_email: ?string, via: string}
     */
    public function resolve(EmailMessage $message): array
    {
        $from = strtolower(trim($message->fromAddress));
        if ($from !== '') {
            $user = $this->users->findByEmail($from);
            if ($user !== null) {
                return ['user' => $user, 'matched_email' => $from, 'via' => 'from'];
            }
        }

        $vendorFrom = $from !== '' && EmailConfirmationDetector::isKnownVendorAddress($from);
        // Only fall back for vendor (or empty) From — never reassign an unknown teammate From via To:.
        if ($from !== '' && !$vendorFrom) {
            return ['user' => null, 'matched_email' => null, 'via' => 'none'];
        }

        foreach ($this->ownerCandidates($message) as $email) {
            if ($email === $from) {
                continue;
            }
            if (EmailConfirmationDetector::isKnownVendorAddress($email)) {
                continue;
            }
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                return [
                    'user' => $user,
                    'matched_email' => $email,
                    'via' => $vendorFrom ? 'vendor_recipient_fallback' : 'recipient_fallback',
                ];
            }
        }

        return ['user' => null, 'matched_email' => null, 'via' => 'none'];
    }

    /**
     * Ordered unique candidate addresses for ownership (excluding outer From).
     *
     * @return list<string>
     */
    public function ownerCandidates(EmailMessage $message): array
    {
        $out = [];
        $seen = [];

        $add = static function (string $email) use (&$out, &$seen): void {
            $email = strtolower(trim($email));
            if ($email === '' || !str_contains($email, '@') || isset($seen[$email])) {
                return;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return;
            }
            $seen[$email] = true;
            $out[] = $email;
        };

        foreach ($message->recipientAddresses as $addr) {
            $add($addr);
        }

        foreach (self::extractBodyRecipientHints($message->bestText()) as $addr) {
            $add($addr);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function extractBodyRecipientHints(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $found = [];

        // Hilton / Marriott style: "delivered to\ndavid@… (" or "sent to email@…"
        $patterns = [
            '/delivered\s+to\s*[\s:]*\(?\s*([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})\s*\)?/iu',
            '/(?:this\s+)?(?:e-?mail|message)\s+(?:was\s+)?sent\s+to\s*[\s:]*\(?\s*([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})\s*\)?/iu',
            '/intended\s+for\s*[\s:]*\(?\s*([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})\s*\)?/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $m) === false) {
                continue;
            }
            foreach ($m[1] as $email) {
                $found[] = strtolower(trim((string) $email));
            }
        }

        return $found;
    }

    /**
     * Parse IMAP/RFC822 header block for recipient-related addresses.
     *
     * @return list<string> lowercase emails, Delivered-To / X-Original-To before To / Cc
     */
    public static function recipientsFromHeaderBlock(string $rawHeaders): array
    {
        $rawHeaders = str_replace(["\r\n", "\r"], "\n", $rawHeaders);
        // Unfold continued header lines
        $rawHeaders = (string) preg_replace("/\n[ \t]+/", ' ', $rawHeaders);

        $priority = [
            'delivered-to',
            'x-original-to',
            'envelope-to',
            'x-envelope-to',
            'to',
            'cc',
            'resent-to',
        ];
        /** @var array<string, list<string>> $byName */
        $byName = [];
        foreach (explode("\n", $rawHeaders) as $line) {
            if (preg_match('/^([A-Za-z0-9\-]+)\s*:\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $name = strtolower($m[1]);
            if (!in_array($name, $priority, true)) {
                continue;
            }
            $byName[$name] ??= [];
            foreach (self::emailsFromAddressList($m[2]) as $email) {
                $byName[$name][] = $email;
            }
        }

        $out = [];
        $seen = [];
        foreach ($priority as $name) {
            foreach ($byName[$name] ?? [] as $email) {
                if (isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;
                $out[] = $email;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function emailsFromAddressList(string $headerValue): array
    {
        $out = [];
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $headerValue, $m) === false) {
            return [];
        }
        foreach ($m[0] as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = $email;
            }
        }

        return $out;
    }
}
