<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * Recursively collect text/plain and text/html from an IMAP fetchstructure tree.
 * Handles ProtonMail multipart/signed wrappers, nested alternative/related,
 * and message/rfc822 embeds that a top-level-only walk misses.
 */
final class MimeBodyExtractor
{
    /** IMAP TYPETEXT */
    private const TYPE_TEXT = 0;
    /** IMAP TYPEMULTIPART */
    private const TYPE_MULTIPART = 1;
    /** IMAP TYPEMESSAGE */
    private const TYPE_MESSAGE = 2;

    /**
     * @param callable(string): string $fetchRaw Part number (e.g. "1.1.1") -> raw body bytes
     * @return array{0: string, 1: string} [plainText, html]
     */
    public static function extract(object $structure, callable $fetchRaw): array
    {
        $plain = '';
        $html = '';
        self::walk($structure, '', $fetchRaw, $plain, $html);
        return [$plain, $html];
    }

    /**
     * @param callable(string): string $fetchRaw
     */
    private static function walk(
        object $part,
        string $partNumber,
        callable $fetchRaw,
        string &$plain,
        string &$html,
    ): void {
        $type = (int) ($part->type ?? self::TYPE_TEXT);
        $subtype = strtoupper((string) ($part->subtype ?? 'PLAIN'));

        if ($type === self::TYPE_MULTIPART && isset($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $child) {
                if (!is_object($child)) {
                    continue;
                }
                $childNumber = $partNumber === ''
                    ? (string) ($index + 1)
                    : $partNumber . '.' . ($index + 1);
                self::walk($child, $childNumber, $fetchRaw, $plain, $html);
            }
            return;
        }

        // Embedded message (forward-as-attachment / message/rfc822).
        if ($type === self::TYPE_MESSAGE && isset($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $child) {
                if (!is_object($child)) {
                    continue;
                }
                // Nested parts are numbered under this part (e.g. 2.1, 2.1.1).
                $childNumber = $partNumber === ''
                    ? (string) ($index + 1)
                    : $partNumber . '.' . ($index + 1);
                self::walk($child, $childNumber, $fetchRaw, $plain, $html);
            }
            return;
        }

        if ($type !== self::TYPE_TEXT) {
            return;
        }
        if ($subtype !== 'PLAIN' && $subtype !== 'HTML') {
            return;
        }

        $fetchNumber = $partNumber === '' ? '1' : $partNumber;
        $raw = $fetchRaw($fetchNumber);
        $decoded = self::decodePart($raw, (int) ($part->encoding ?? 0));
        if ($subtype === 'PLAIN') {
            $plain .= $decoded;
        } else {
            $html .= $decoded;
        }
    }

    public static function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw, true), // ENCBASE64
            4 => (string) quoted_printable_decode($raw), // ENCQUOTEDPRINTABLE
            default => $raw,
        };
    }
}
