<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\NotImplementedException;

/**
 * Future mail source: Microsoft Graph API against an M365 Enterprise
 * mailbox (application permissions, Mail.ReadWrite, client-credentials
 * flow against a dedicated shared mailbox). Conforms to
 * MailSourceInterface for the same reason as GmailApiSource -- see that
 * file's docblock. Not implemented; every method throws.
 */
final class M365GraphSource implements MailSourceInterface
{
    public function fetchUnseenMessages(): array
    {
        throw new NotImplementedException('Microsoft 365 Graph mail source', 'a future phase');
    }

    public function markProcessed(string $uid): void
    {
        throw new NotImplementedException('Microsoft 365 Graph mail source', 'a future phase');
    }

    public function markFailed(string $uid, string $reason): void
    {
        throw new NotImplementedException('Microsoft 365 Graph mail source', 'a future phase');
    }

    public function disconnect(): void
    {
        throw new NotImplementedException('Microsoft 365 Graph mail source', 'a future phase');
    }
}
