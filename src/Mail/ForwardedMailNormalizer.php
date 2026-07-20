<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * Normalizes teammate forwards from Gmail, Outlook/Exchange, Proton Mail,
 * Yahoo, Apple Mail, and similar clients so detectors/parsers see the
 * underlying confirmation content (not just the outer Fw: wrapper).
 *
 * Ownership still uses the outer From: (teammate address). Subject/body are
 * cleaned for vendor detection and field extraction.
 */
final class ForwardedMailNormalizer
{
    /**
     * Markers that introduce the original message in a forward.
     * Order does not matter; first match wins for "content after marker".
     *
     * @var list<string>
     */
    private const FORWARD_MARKERS = [
        '/^-{2,}\s*Forwarded message\s*-{2,}/im',
        '/^-{2,}\s*Forwarded Message\s*-{2,}/im',
        '/^-{2,}\s*Original Message\s*-{2,}/im',
        '/^Begin forwarded message:\s*$/im',
        '/^-{5,}\s*Original Message\s*-{5,}/im',
        '/^_{5,}\s*$/m', // Outlook/Exchange separator line
        '/^-{5,}\s*$/m',
    ];

    public static function normalize(EmailMessage $message): EmailMessage
    {
        $plain = self::preparePlain($message->bodyPlain);
        $html = self::prepareHtml($message->bodyHtml);

        if ($plain === '' && $html !== '') {
            $plain = self::preparePlain(self::htmlToRoughText($html));
        }

        $meta = self::extractOriginalHeaders($plain);
        $subject = $message->subject;
        if ($meta['subject'] !== null && trim($meta['subject']) !== '') {
            $subject = $meta['subject'];
        } else {
            $subject = self::stripForwardSubjectPrefix($subject);
        }

        // Ensure detector can see original sender domain even if markers differ.
        if ($meta['from'] !== null && $meta['from'] !== '') {
            $needle = strtolower($meta['from']);
            if (!str_contains(strtolower($plain), $needle)) {
                $plain = 'From: ' . $meta['from'] . "\n" . $plain;
            }
        }

        return new EmailMessage(
            uid: $message->uid,
            fromAddress: $message->fromAddress,
            subject: $subject,
            receivedAt: $message->receivedAt,
            bodyPlain: $plain,
            bodyHtml: $html,
        );
    }

    public static function stripForwardSubjectPrefix(string $subject): string
    {
        $subject = trim($subject);
        // Fw: / Fwd: / FW: / Re: chains (Outlook sometimes stacks them)
        while (preg_match('/^(?:(?:fw|fwd|re)\s*:\s*)+/i', $subject) === 1) {
            $subject = trim((string) preg_replace('/^(?:(?:fw|fwd|re)\s*:\s*)+/i', '', $subject));
        }
        return $subject;
    }

    /**
     * Remove quote prefixes and soft line-noise common in Proton/Outlook forwards.
     */
    public static function dequoteText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $line) {
            // Proton/Gmail quote markers; also "|> " style
            $line = (string) preg_replace('/^(?:>+\s?|\|\s?)+/', '', $line);
            // Soft hyphen / empty quote leftovers
            if (preg_match('/^=\s*$/', trim($line)) === 1) {
                continue;
            }
            $out[] = $line;
        }
        $joined = implode("\n", $out);
        // Join quoted-printable soft breaks left in plain: "get =\nyour"
        $joined = (string) preg_replace('/=\n/', '', $joined);
        $joined = (string) preg_replace("/\n{3,}/", "\n\n", $joined);
        return trim($joined);
    }

    private static function preparePlain(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        $plain = self::dequoteText($plain);
        return self::preferContentAfterForwardMarker($plain);
    }

    private static function prepareHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // Gmail sometimes wraps the original in a blockquote / gmail_quote div.
        if (preg_match(
            '#(<div[^>]*class="[^"]*gmail_quote[^"]*"[^>]*>.*)$#is',
            $html,
            $m
        ) === 1) {
            return trim($m[1]);
        }
        if (preg_match('#(<blockquote[^>]*>.*)</blockquote>#is', $html, $m) === 1) {
            // Prefer innermost large blockquote if present; take last match body.
            return trim($m[1] . '</blockquote>');
        }
        return $html;
    }

    private static function preferContentAfterForwardMarker(string $text): string
    {
        $bestPos = null;
        foreach (self::FORWARD_MARKERS as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = (int) $m[0][1] + strlen($m[0][0]);
                if ($bestPos === null || $pos < $bestPos) {
                    $bestPos = $pos;
                }
            }
        }
        if ($bestPos === null) {
            return $text;
        }
        $after = trim(substr($text, $bestPos));
        // Keep original if the "after" slice is tiny (false-positive separator).
        if (strlen($after) < 40) {
            return $text;
        }
        return $after;
    }

    /**
     * @return array{from: ?string, subject: ?string}
     */
    private static function extractOriginalHeaders(string $text): array
    {
        $from = null;
        $subject = null;

        // Proton / Gmail / Yahoo style header block
        if (preg_match(
            '/^From:\s*(.+)$/mi',
            $text,
            $m
        ) === 1) {
            $from = self::emailFromHeaderLine(trim($m[1]));
        }

        if (preg_match('/^Subject:\s*(.+)$/mi', $text, $m) === 1) {
            $subject = self::stripForwardSubjectPrefix(trim($m[1]));
        }

        // Outlook: "From: Name <email> Sent: ... Subject: ..."
        if ($subject === null && preg_match('/Subject:\s*(.+?)(?:\r?\n|$)/i', $text, $m) === 1) {
            $subject = self::stripForwardSubjectPrefix(trim($m[1]));
        }

        return ['from' => $from, 'subject' => $subject];
    }

    private static function emailFromHeaderLine(string $line): ?string
    {
        if (preg_match('/<([^>]+@[^>]+)>/', $line, $m) === 1) {
            return strtolower(trim($m[1]));
        }
        if (preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $line, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    private static function htmlToRoughText(string $html): string
    {
        $text = preg_replace('#(?is)<script[^>]*>.*?</script>#', ' ', $html) ?? $html;
        $text = preg_replace('#(?is)<style[^>]*>.*?</style>#', ' ', $text) ?? $text;
        $text = preg_replace('#(?i)<br\s*/?>#', "\n", $text) ?? $text;
        $text = preg_replace('#(?i)</(p|div|tr|li|h[1-6]|td)>#', "\n", $text) ?? $text;
        $text = strip_tags($text);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
