<?php

declare(strict_types=1);

namespace NexWaypont\Mail;

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

        $formats = ['M j, Y', 'F j, Y', 'm/d/Y', 'Y-m-d', 'D, M j, Y', 'j M Y'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, trim($raw));
            if ($date !== false) {
                $this->fieldsFound++;
                return $date->format('Y-m-d');
            }
        }

        // Last resort: let PHP's general parser take a shot.
        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            $this->fieldsFound++;
            return date('Y-m-d', $timestamp);
        }

        return null;
    }
}
