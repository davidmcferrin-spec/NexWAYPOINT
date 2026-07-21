<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * In-memory representation of one inbound email during detection + parsing.
 * A short-lived .eml copy may be written under storage/mail_raw/ for
 * system-admin review (MAIL_RAW_RETENTION_DAYS); it is never stored in the DB.
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
            return ForwardedMailNormalizer::dequoteText($this->bodyPlain);
        }
        // Fall back to a crude HTML-to-text strip if only HTML was available.
        return ForwardedMailNormalizer::dequoteText(
            trim(html_entity_decode(strip_tags($this->bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        );
    }
}
