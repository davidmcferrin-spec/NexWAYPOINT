<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * In-memory representation of one inbound email. NEVER persisted as-is --
 * bodyPlain/bodyHtml exist only for the duration of detection + parsing and
 * must not be written to the database or logged. See parse_log's schema
 * comment for why (privacy constraint: raw email content never stored).
 */
final class EmailMessage
{
    public function __construct(
        public readonly string $uid,
        public readonly string $fromAddress,
        public readonly string $subject,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly string $bodyPlain,
        public readonly string $bodyHtml,
    ) {
    }

    public function bestText(): string
    {
        if (trim($this->bodyPlain) !== '') {
            return $this->bodyPlain;
        }
        // Fall back to a crude HTML-to-text strip if only HTML was available.
        return trim(html_entity_decode(strip_tags($this->bodyHtml)));
    }
}
