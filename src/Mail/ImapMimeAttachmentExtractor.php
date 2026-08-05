<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * Collects file attachments (especially application/pdf) from an IMAP structure tree.
 *
 * @phpstan-type Attachment array{filename: string, mime_type: string, content: string}
 */
final class ImapMimeAttachmentExtractor
{
    /**
     * @param callable(string): string $fetchBody
     * @return list<Attachment>
     */
    public static function extract(object|false $structure, callable $fetchBody): array
    {
        if ($structure === false) {
            return [];
        }
        /** @var list<Attachment> $out */
        $out = [];
        self::walk($structure, '', $fetchBody, $out);
        return $out;
    }

    /**
     * @param callable(string): string $fetchBody
     * @param list<Attachment> $out
     */
    private static function walk(
        object $part,
        string $partNumber,
        callable $fetchBody,
        array &$out,
    ): void {
        $type = (int) ($part->type ?? 0);
        $subtype = strtoupper((string) ($part->subtype ?? ''));
        $hasChildren = isset($part->parts) && is_array($part->parts) && $part->parts !== [];

        if ($hasChildren && ($type === 1 || $type === 2 || $subtype === 'SIGNED'
            || $subtype === 'MIXED' || $subtype === 'ALTERNATIVE' || $subtype === 'RELATED'
            || $subtype === 'RFC822' || $subtype === 'DIGEST')) {
            foreach ($part->parts as $index => $child) {
                $childNumber = $partNumber === ''
                    ? (string) ($index + 1)
                    : $partNumber . '.' . ($index + 1);
                self::walk($child, $childNumber, $fetchBody, $out);
            }
            return;
        }

        // Skip body text parts.
        if ($type === 0 && ($subtype === 'PLAIN' || $subtype === 'HTML')) {
            return;
        }

        $filename = self::filenameFromPart($part);
        $disposition = strtolower((string) ($part->disposition ?? ''));
        $isAttachment = $disposition === 'attachment'
            || $disposition === 'inline'
            || $filename !== null
            || $type === 3 /* APPLICATION */;

        if (!$isAttachment) {
            return;
        }

        $fetchNum = $partNumber !== '' ? $partNumber : '1';
        $raw = $fetchBody($fetchNum);
        $decoded = ImapMimeBodyExtractor::decodePart($raw, (int) ($part->encoding ?? 0));
        if ($decoded === '') {
            return;
        }

        $mime = self::mimeFromPart($type, $subtype);
        $name = $filename ?? ('attachment-' . $fetchNum . self::extensionForMime($mime));

        // Keep PDFs and common image receipts; skip huge blobs.
        $keep = str_contains($mime, 'pdf')
            || str_starts_with($decoded, '%PDF')
            || str_ends_with(strtolower($name), '.pdf')
            || str_starts_with($mime, 'image/');
        if (!$keep) {
            return;
        }
        if (strlen($decoded) > 8 * 1024 * 1024) {
            return;
        }

        $out[] = [
            'filename' => $name,
            'mime_type' => $mime,
            'content' => $decoded,
        ];
    }

    private static function filenameFromPart(object $part): ?string
    {
        if (isset($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                $attr = strtolower((string) ($param->attribute ?? ''));
                if ($attr === 'filename' && isset($param->value) && trim((string) $param->value) !== '') {
                    return self::decodeMimeHeader((string) $param->value);
                }
            }
        }
        if (isset($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $param) {
                $attr = strtolower((string) ($param->attribute ?? ''));
                if ($attr === 'name' && isset($param->value) && trim((string) $param->value) !== '') {
                    return self::decodeMimeHeader((string) $param->value);
                }
            }
        }
        return null;
    }

    private static function decodeMimeHeader(string $value): string
    {
        if (function_exists('imap_mime_header_decode')) {
            $parts = @imap_mime_header_decode($value);
            if (is_array($parts)) {
                $out = '';
                foreach ($parts as $part) {
                    $out .= (string) ($part->text ?? '');
                }
                if (trim($out) !== '') {
                    return trim($out);
                }
            }
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && trim($decoded) !== '') {
                return trim($decoded);
            }
        }
        return trim($value);
    }

    private static function mimeFromPart(int $type, string $subtype): string
    {
        $primary = match ($type) {
            0 => 'text',
            1 => 'multipart',
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            default => 'application',
        };
        $sub = $subtype !== '' ? strtolower($subtype) : 'octet-stream';
        return $primary . '/' . $sub;
    }

    private static function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => '.pdf',
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            default => '.bin',
        };
    }
}
