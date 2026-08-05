<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

/**
 * Minimal multi-page PDF writer (Helvetica, text only).
 * No Composer dependency — enough for itinerary / confirmation summaries.
 */
final class SimplePdf
{
    /** @var list<string> */
    private array $lines = [];

    public function title(string $text): self
    {
        $this->lines[] = 'TITLE:' . $text;
        return $this;
    }

    public function heading(string $text): self
    {
        $this->lines[] = 'HEAD:' . $text;
        return $this;
    }

    public function line(string $text): self
    {
        $this->lines[] = 'BODY:' . $text;
        return $this;
    }

    public function blank(): self
    {
        $this->lines[] = 'BLANK';
        return $this;
    }

    /**
     * @param list<string> $rows
     */
    public function lines(array $rows): self
    {
        foreach ($rows as $row) {
            $this->line($row);
        }
        return $this;
    }

    public function render(): string
    {
        $contentStreams = $this->buildContentStreams();
        $fontId = 3;
        $pageStartId = 4;
        $contentStartId = $pageStartId + count($contentStreams);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $kids = [];
        foreach ($contentStreams as $i => $stream) {
            $pageId = $pageStartId + $i;
            $contentId = $contentStartId + $i;
            $kids[] = $pageId . ' 0 R';
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . "/Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count '
            . count($contentStreams) . ' >>';

        $maxId = $contentStartId + count($contentStreams) - 1;
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= 'xref\n0 ' . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return list<string>
     */
    private function buildContentStreams(): array
    {
        $maxY = 720.0;
        $minY = 54.0;
        $pages = [];
        $y = $maxY;
        $ops = ['BT', '/F1 11 Tf', "50 {$y} Td"];

        $flushPage = static function () use (&$ops, &$pages, &$y, $maxY): void {
            $ops[] = 'ET';
            $pages[] = implode("\n", $ops);
            $y = $maxY;
            $ops = ['BT', '/F1 11 Tf', "50 {$y} Td"];
        };

        foreach ($this->lines as $raw) {
            if ($raw === 'BLANK') {
                if ($y - 10 < $minY) {
                    $flushPage();
                }
                $ops[] = '0 -10 Td';
                $y -= 10;
                continue;
            }

            $role = 'BODY';
            $text = $raw;
            if (str_starts_with($raw, 'TITLE:')) {
                $role = 'TITLE';
                $text = substr($raw, 6);
            } elseif (str_starts_with($raw, 'HEAD:')) {
                $role = 'HEAD';
                $text = substr($raw, 5);
            } elseif (str_starts_with($raw, 'BODY:')) {
                $text = substr($raw, 5);
            }

            [$fontCmd, $leading, $width] = match ($role) {
                'TITLE' => ['/F1 16 Tf', 20.0, 70],
                'HEAD' => ['/F1 12 Tf', 16.0, 80],
                default => ['/F1 10 Tf', 13.0, 95],
            };

            foreach ($this->wrap($text, $width) as $chunk) {
                if ($y - $leading < $minY) {
                    $flushPage();
                }
                $ops[] = $fontCmd;
                $ops[] = '0 -' . $leading . ' Td';
                $ops[] = '(' . self::escape($chunk) . ') Tj';
                $y -= $leading;
            }
        }

        $ops[] = 'ET';
        $pages[] = implode("\n", $ops);
        return $pages;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }
        $words = explode(' ', $text);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current . ' ' . $word;
            if (strlen($trial) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $trial;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines !== [] ? $lines : [''];
    }

    private static function escape(string $text): string
    {
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/u', '?', $text) ?? $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
