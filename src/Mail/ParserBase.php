<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * Shared regex-extraction helpers for concrete parsers. Confidence scoring
 * is tracked per-instance across the last parse() call -- concrete parsers
 * call recordFieldFound()/recordFieldMissing() as they extract each field
 * and confidenceScore() averages the result.
 */
abstract class ParserBase implements ParserInterface
{
    private int $fieldsFound = 0;
    private int $fieldsAttempted = 0;

    abstract public function parse(EmailMessage $message): ?array;

    public function confidenceScore(): float
    {
        if ($this->fieldsAttempted === 0) {
            return 0.0;
        }
        return round($this->fieldsFound / $this->fieldsAttempted, 2);
    }

    protected function resetConfidenceTracking(): void
    {
        $this->fieldsFound = 0;
        $this->fieldsAttempted = 0;
    }

    protected function recordField(bool $found): void
    {
        $this->fieldsAttempted++;
        if ($found) {
            $this->fieldsFound++;
        }
    }

    /**
     * Runs $pattern against $text and tracks it toward the confidence score.
     * Returns the first capture group, or null if no match.
     */
    protected function extractPattern(string $pattern, string $text): ?string
    {
        $this->fieldsAttempted++;
        if (preg_match($pattern, $text, $matches) === 1 && isset($matches[1])) {
            $value = trim($matches[1]);
            if ($value !== '') {
                $this->fieldsFound++;
                return $value;
            }
        }
        return null;
    }

    /**
     * Tries each pattern in order, returning the first match. Counts as a
     * single field attempt regardless of how many patterns were tried.
     *
     * @param string[] $patterns
     */
    protected function extractFirstMatch(array $patterns, string $text): ?string
    {
        $this->fieldsAttempted++;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1 && isset($matches[1])) {
                $value = trim($matches[1]);
                if ($value !== '') {
                    $this->fieldsFound++;
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Attempts to parse a date string in several common confirmation-email
     * formats into Y-m-d. Counts toward confidence like extractPattern().
     */
    protected function parseFlexibleDate(?string $raw): ?string
    {
        $this->fieldsAttempted++;
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $formats = [
            'M j, Y', 'F j, Y', 'm/d/Y', 'Y-m-d', 'D, M j, Y', 'j M Y',
            'D, M j Y', 'l, F j, Y', 'l, M j, Y', 'M j Y',
        ];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, trim($raw));
            if ($date !== false) {
                $this->fieldsFound++;
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            $this->fieldsFound++;
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Parse a local wall-clock datetime into Y-m-d H:i:s.
     * Airline JSON-LD often stamps local times with a trailing Z — strip it.
     */
    protected function parseFlexibleDateTime(?string $raw): ?string
    {
        $this->fieldsAttempted++;
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        // AA Schema.org: local wall clock falsely marked as Z.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::\d{2})?Z$/', $raw, $m) === 1) {
            $this->fieldsFound++;
            return $m[1] . ' ' . $m[2] . ':00';
        }

        $formats = [
            'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'D, M j, Y g:i A', 'l, F j, Y g:i A', 'M j, Y g:i A',
            'm/d/Y g:i A', 'Y-m-d g:i A',
        ];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $raw);
            if ($date !== false) {
                $this->fieldsFound++;
                return $date->format('Y-m-d H:i:s');
            }
        }

        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            $this->fieldsFound++;
            return date('Y-m-d H:i:s', $timestamp);
        }

        return null;
    }

    protected function messageText(EmailMessage $message): string
    {
        if (trim($message->bodyPlain) !== '') {
            return $message->bodyPlain;
        }
        return $this->htmlToText($message->bodyHtml);
    }

    protected function htmlToText(string $html): string
    {
        $text = preg_replace('#(?is)<script[^>]*>.*?</script>#', ' ', $html) ?? $html;
        $text = preg_replace('#(?is)<style[^>]*>.*?</style>#', ' ', $text) ?? $text;
        $text = preg_replace('#(?i)<br\s*/?>#', "\n", $text) ?? $text;
        $text = preg_replace('#(?i)</(p|div|tr|li|h[1-6]|td)>#', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function extractJsonLd(string $html): array
    {
        $blocks = [];
        if (preg_match_all(
            '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        ) === false) {
            return [];
        }
        foreach ($matches[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded)) {
                $blocks[] = $decoded;
            }
        }
        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    protected function flattenJsonLd(array $blocks): array
    {
        $out = [];
        $walk = function ($node) use (&$walk, &$out): void {
            if (!is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
                return;
            }
            $out[] = $node;
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        foreach ($blocks as $block) {
            $walk($block);
        }
        return $out;
    }

    protected function normalizeFlightNumber(?string $raw, ?string $iata = null): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
        if ($raw === '') {
            return null;
        }
        if ($iata !== null) {
            $iata = strtoupper($iata);
            if (str_starts_with($raw, $iata) && strlen($raw) > strlen($iata)) {
                $raw = substr($raw, strlen($iata));
            }
        }
        $raw = ltrim($raw, '0');
        return $raw !== '' ? $raw : '0';
    }

    /**
     * Combine a date (Y-m-d or flexible) + time like "7:52 PM" into Y-m-d H:i:s.
     */
    protected function combineDateAndTime(?string $dateRaw, ?string $timeRaw): ?string
    {
        if ($dateRaw === null || $timeRaw === null) {
            return null;
        }
        // Avoid double-counting confidence: parse date without the tracker.
        $date = null;
        $formats = ['Y-m-d', 'M j, Y', 'F j, Y', 'm/d/Y', 'D, M j, Y', 'l, F j, Y', 'l, M j, Y'];
        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, trim($dateRaw));
            if ($parsed !== false) {
                $date = $parsed->format('Y-m-d');
                break;
            }
        }
        if ($date === null) {
            $ts = strtotime($dateRaw);
            if ($ts !== false) {
                $date = date('Y-m-d', $ts);
            }
        }
        $this->recordField($date !== null);
        if ($date === null) {
            return null;
        }

        $timeRaw = trim($timeRaw);
        $dt = \DateTime::createFromFormat('Y-m-d g:i A', $date . ' ' . strtoupper($timeRaw));
        if ($dt === false) {
            $dt = \DateTime::createFromFormat('Y-m-d g:iA', $date . ' ' . strtoupper(str_replace(' ', '', $timeRaw)));
        }
        if ($dt === false) {
            $dt = \DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $timeRaw);
        }
        $this->recordField($dt !== false);
        if ($dt === false) {
            return $date . ' 00:00:00';
        }
        return $dt->format('Y-m-d H:i:s');
    }

    protected function monthNum(string $mon): string
    {
        $map = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
            'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
            'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
        ];
        $key = strtolower(substr($mon, 0, 3));
        return $map[$key] ?? '01';
    }
}
