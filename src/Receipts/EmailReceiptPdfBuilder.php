<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ForwardedMailNormalizer;

/**
 * Renders a vendor confirmation email (direct or forwarded) into a text PDF.
 * Prefers cleaned HTML (style/script stripped) over a junk text/plain part.
 * Not a visual HTML renderer — no Composer / browser on DreamHost.
 */
final class EmailReceiptPdfBuilder
{
    public function build(EmailMessage $message): string
    {
        $pdf = new SimplePdf();
        $pdf->title('Vendor confirmation email');
        $pdf->line('From: ' . $message->fromAddress);
        $pdf->line('Subject: ' . $this->oneLine($message->subject));
        $pdf->line('Received: ' . $message->receivedAt->format('Y-m-d H:i:s T'));
        $pdf->blank();
        $pdf->heading('Message');

        $body = $this->bodyText($message);
        if ($body === '') {
            $pdf->line('(No message body.)');
            return $pdf->render();
        }

        $lines = preg_split('/\R/u', $body) ?: [];
        $emitted = 0;
        $maxLines = 400;
        foreach ($lines as $line) {
            if ($emitted >= $maxLines) {
                $pdf->blank();
                $pdf->line('… (truncated for length)');
                break;
            }
            $line = rtrim($line);
            if ($line === '') {
                $pdf->blank();
            } else {
                $pdf->line($line);
            }
            $emitted++;
        }

        return $pdf->render();
    }

    public function hasRenderableBody(EmailMessage $message): bool
    {
        return $this->bodyText($message) !== '';
    }

    public function bodyText(EmailMessage $message): string
    {
        $fromHtml = $this->fromHtml($message->bodyHtml);
        $fromPlain = $this->fromPlain($message->bodyPlain);

        if ($fromHtml !== '' && ($fromPlain === '' || self::looksLikeStylesheet($fromPlain))) {
            return $fromHtml;
        }
        if ($fromHtml !== '' && strlen($fromHtml) >= strlen($fromPlain)) {
            return $fromHtml;
        }
        if ($fromPlain !== '') {
            return $fromPlain;
        }
        return $fromHtml;
    }

    private function fromHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $text = ForwardedMailNormalizer::htmlToText($html);
        $text = trim(ForwardedMailNormalizer::dequoteText($text));
        return self::stripLeakedCss($text);
    }

    private function fromPlain(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        return self::stripLeakedCss(trim(ForwardedMailNormalizer::dequoteText($plain)));
    }

    /**
     * Drop leftover stylesheet lines (strip_tags leaves <style> contents).
     */
    public static function stripLeakedCss(string $text): string
    {
        $text = preg_replace('/@media[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/s', "\n", $text) ?? $text;
        $text = preg_replace('/:root\s*\{[^{}]*\}/s', "\n", $text) ?? $text;

        $kept = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $kept[] = '';
                continue;
            }
            if (self::isCssLine($trim)) {
                continue;
            }
            $kept[] = $line;
        }

        $out = implode("\n", $kept);
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;
        return trim($out);
    }

    public static function looksLikeStylesheet(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $hits = preg_match_all('/!important|:root\s*\{|@media\s+|mso-|proton-disabled/i', $text);
        return is_int($hits) && $hits >= 3;
    }

    private static function isCssLine(string $line): bool
    {
        if (str_contains($line, '!important')) {
            return true;
        }
        if (preg_match('/^[:@.#][\w-]*\s*\{/', $line) === 1) {
            return true;
        }
        if (preg_match('/[{}]\s*$/', $line) === 1 && preg_match('/[{}:;]/', $line) === 1) {
            return true;
        }
        if (
            preg_match('/;\s*$/', $line) === 1
            && preg_match('/:\s*[^;]+;/', $line) === 1
            && preg_match('/(\d+px|#([0-9a-fA-F]{3,8})|rgba?\(|hsl\()/i', $line) === 1
        ) {
            return true;
        }
        return false;
    }

    private function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
