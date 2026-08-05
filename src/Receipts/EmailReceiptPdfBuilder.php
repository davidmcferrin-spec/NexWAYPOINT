<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\ForwardedMailNormalizer;

/**
 * Renders a vendor confirmation email (direct or forwarded) into a text PDF.
 * Uses the same dequoted body parsers see — not DB trip/stay reconstruction.
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

    private function bodyText(EmailMessage $message): string
    {
        $text = trim($message->bestText());
        if ($text !== '') {
            return $text;
        }
        $html = trim($message->bodyHtml);
        if ($html === '') {
            return '';
        }
        return trim(ForwardedMailNormalizer::dequoteText(
            html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
    }

    private function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
