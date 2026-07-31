<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * No-op mail source for admin re-parse from stored .eml (no IMAP side effects).
 */
final class NullMailSource implements MailSourceInterface
{
    public function fetchUnseenMessages(): array
    {
        return [];
    }

    public function markProcessed(string $uid): void
    {
    }

    public function markFailed(string $uid, string $reason): void
    {
    }

    public function disconnect(): void
    {
    }
}
