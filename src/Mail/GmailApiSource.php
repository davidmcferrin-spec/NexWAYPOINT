<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\NotImplementedException;

/**
 * Future mail source: Gmail API (OAuth2 refresh token, gmail.modify scope).
 * Conforms to MailSourceInterface so MailPoller can swap sources via
 * MAIL_SOURCE env var without any orchestration code changes -- but every
 * method throws until this is actually built. Do not set MAIL_SOURCE=gmail
 * in production; it will fail loudly and immediately, which is the point.
 *
 * To implement this for real: Google API PHP client (composer require
 * google/apiclient), OAuth2 authorization-code flow for the one-time
 * consent grant, refresh-token storage encrypted at rest, then
 * users.messages.list(q="is:unread") + users.messages.modify() to apply
 * PROCESSED/PARSE_FAILED labels in place of IMAP folder moves.
 */
final class GmailApiSource implements MailSourceInterface
{
    public function fetchUnseenMessages(): array
    {
        throw new NotImplementedException('Gmail API mail source', 'a future phase');
    }

    public function markProcessed(string $uid): void
    {
        throw new NotImplementedException('Gmail API mail source', 'a future phase');
    }

    public function markFailed(string $uid, string $reason): void
    {
        throw new NotImplementedException('Gmail API mail source', 'a future phase');
    }

    public function disconnect(): void
    {
        throw new NotImplementedException('Gmail API mail source', 'a future phase');
    }
}
