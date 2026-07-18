<?php

declare(strict_types=1);

namespace NexWaypont\Mail;

/**
 * Contract for any inbox NexWAYPONT polls for forwarded confirmations.
 * v1 ships DreamHostImapSource. GmailApiSource and M365GraphSource
 * implement this interface but throw NotImplementedException -- see
 * README roadmap for what's needed to bring them online.
 */
interface MailSourceInterface
{
    /**
     * @return EmailMessage[]
     */
    public function fetchUnseenMessages(): array;

    /**
     * Mark a message successfully processed: move it out of the inbox
     * (and delete it, if MAIL_DELETE_ON_SUCCESS is set) so it's never
     * re-polled.
     */
    public function markProcessed(string $uid): void;

    /**
     * Mark a message as failed to parse: leave it visible for manual
     * review (e.g. moved to a "ParseFailed" folder) rather than deleting it.
     */
    public function markFailed(string $uid, string $reason): void;

    public function disconnect(): void;
}
