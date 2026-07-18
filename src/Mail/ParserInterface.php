<?php

declare(strict_types=1);

namespace NexWaypont\Mail;

interface ParserInterface
{
    /**
     * Extract structured fields from a confirmation email. Returns null if
     * this parser cannot find enough signal to produce a usable record --
     * that's a normal outcome, not an error, and should route the message
     * to PARSE_FAILED for manual review.
     *
     * @return array<string, mixed>|null
     */
    public function parse(EmailMessage $message): ?array;

    /**
     * 0.0-1.0 confidence in the last parse() result. Segments below
     * MAIL_MIN_PARSE_CONFIDENCE go to manual review regardless of whether
     * parse() technically "succeeded".
     */
    public function confidenceScore(): float;
}
