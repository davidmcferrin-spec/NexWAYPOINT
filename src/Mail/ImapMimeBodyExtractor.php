<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * Walks PHP imap_fetchstructure trees and collects text/plain + text/html,
 * including under multipart/signed (ProtonMail), mixed, alternative, related,
 * and message/rfc822 wrappers.
 */
final class ImapMimeBodyExtractor
{
    /**
     * @param callable(string): string $fetchBody fn(partNumber): raw body bytes
     * @return array{0: string, 1: string} [plainText, html]
     */
    public static function extract(object|false $structure, callable $fetchBody): array
    {
        if ($structure === false) {
            return ['', ''];
        }

        $plain = '';
        $html = '';
        self::walk($structure, '', $fetchBody, $plain, $html);
        return [$plain, $html];
    }

    /**
     * @param callable(string): string $fetchBody
     */
    private static function walk(
        object $part,
        string $partNumber,
        callable $fetchBody,
        string &$plain,
        string &$html,
    ): void {
        $type = (int) ($part->type ?? 0);
        $subtype = strtoupper((string) ($part->subtype ?? ''));
        $hasChildren = isset($part->parts) && is_array($part->parts) && $part->parts !== [];

        // TYPEMULTIPART (1) or MESSAGE (2) with nested parts — recurse.
        if ($hasChildren && ($type === 1 || $type === 2 || $subtype === 'SIGNED'
            || $subtype === 'MIXED' || $subtype === 'ALTERNATIVE' || $subtype === 'RELATED'
            || $subtype === 'RFC822' || $subtype === 'DIGEST')) {
            foreach ($part->parts as $index => $child) {
                $childNumber = $partNumber === ''
                    ? (string) ($index + 1)
                    : $partNumber . '.' . ($index + 1);
                self::walk($child, $childNumber, $fetchBody, $plain, $html);
            }
            return;
        }

        // Leaf text parts.
        if ($type === 0 && ($subtype === 'PLAIN' || $subtype === 'HTML')) {
            $fetchNum = $partNumber !== '' ? $partNumber : '1';
            $raw = $fetchBody($fetchNum);
            $decoded = self::decodePart($raw, (int) ($part->encoding ?? 0));
            if ($subtype === 'PLAIN') {
                $plain .= $decoded;
            } else {
                $html .= $decoded;
            }
        }
    }

    public static function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw), // ENCBASE64
            4 => (string) quoted_printable_decode($raw), // ENCQUOTEDPRINTABLE
            default => $raw,
        };
    }
}
