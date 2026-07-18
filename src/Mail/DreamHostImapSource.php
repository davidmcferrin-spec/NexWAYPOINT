<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;

/**
 * IMAP mail source for a DreamHost-hosted dump mailbox (Dovecot IMAP).
 * Uses ext-imap (php-imap) directly -- no external library needed.
 *
 * Lifecycle mirrors the Gmail-label pattern from the original design, but
 * expressed via IMAP folders since generic IMAP has no label concept:
 *   success -> move to IMAP_PROCESSED_FOLDER, optionally delete+expunge
 *   failure -> move to IMAP_FAILED_FOLDER, left there for manual review
 */
final class DreamHostImapSource implements MailSourceInterface
{
    private \IMAP\Connection|false $connection = false;
    private string $mailboxBase;
    private string $inboxFolder;
    private string $processedFolder;
    private string $failedFolder;
    private bool $deleteOnSuccess;

    public function __construct(private readonly Logger $logger)
    {
        $host = Env::getRequired('IMAP_HOST');
        $port = Env::get('IMAP_PORT', '993');
        $encryption = Env::get('IMAP_ENCRYPTION', 'ssl');
        $flags = $encryption === 'none' ? '/imap' : "/imap/{$encryption}";

        $this->mailboxBase = "{{$host}:{$port}{$flags}}";
        $this->inboxFolder = Env::get('IMAP_INBOX_FOLDER', 'INBOX');
        $this->processedFolder = Env::get('IMAP_PROCESSED_FOLDER', 'INBOX.Processed');
        $this->failedFolder = Env::get('IMAP_FAILED_FOLDER', 'INBOX.ParseFailed');
        $this->deleteOnSuccess = Env::getBool('MAIL_DELETE_ON_SUCCESS', true);
    }

    private function connect(): \IMAP\Connection
    {
        if ($this->connection !== false) {
            return $this->connection;
        }

        $username = Env::getRequired('IMAP_USERNAME');
        $password = Env::getRequired('IMAP_PASSWORD');

        $connection = @imap_open($this->mailboxBase . $this->inboxFolder, $username, $password);
        if ($connection === false) {
            $error = imap_last_error() ?: 'unknown error';
            $this->logger->error('IMAP connection failed', ['host' => $this->mailboxBase, 'error' => $error]);
            throw new \RuntimeException("Unable to connect to IMAP mailbox: {$error}");
        }

        $this->ensureFolderExists($connection, $this->processedFolder);
        $this->ensureFolderExists($connection, $this->failedFolder);

        $this->connection = $connection;
        return $connection;
    }

    private function ensureFolderExists(\IMAP\Connection $connection, string $folder): void
    {
        $full = $this->mailboxBase . $folder;
        $existing = @imap_getmailboxes($connection, $this->mailboxBase, '*');
        $found = false;
        if ($existing !== false) {
            foreach ($existing as $mbox) {
                if ($mbox->name === $full) {
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            if (!@imap_createmailbox($connection, imap_utf7_encode($full))) {
                $this->logger->warning('Could not create IMAP folder (may already exist under a different name)', ['folder' => $folder]);
            }
        }
    }

    /**
     * @return EmailMessage[]
     */
    public function fetchUnseenMessages(): array
    {
        $connection = $this->connect();

        $uids = imap_search($connection, 'UNSEEN', SE_UID);
        if ($uids === false) {
            $this->logger->info('No unseen messages found', []);
            return [];
        }

        $messages = [];
        foreach ($uids as $uid) {
            try {
                $messages[] = $this->fetchMessage($connection, (int) $uid);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to fetch/parse message structure', ['uid' => $uid, 'error' => $e->getMessage()]);
            }
        }

        $this->logger->info('Fetched unseen messages', ['count' => count($messages)]);
        return $messages;
    }

    private function fetchMessage(\IMAP\Connection $connection, int $uid): EmailMessage
    {
        $header = imap_fetchheader($connection, $uid, FT_UID);
        $overview = imap_fetch_overview($connection, (string) $uid, FT_UID);
        $structure = imap_fetchstructure($connection, $uid, FT_UID);

        $subject = '';
        $from = '';
        $date = new \DateTimeImmutable('now');

        if ($overview !== false && isset($overview[0])) {
            $subject = isset($overview[0]->subject) ? (string) imap_utf8($overview[0]->subject) : '';
            $from = isset($overview[0]->from) ? (string) $overview[0]->from : '';
            if (isset($overview[0]->date)) {
                try {
                    $date = new \DateTimeImmutable((string) $overview[0]->date);
                } catch (\Exception) {
                    // keep default "now"
                }
            }
        }

        [$plain, $html] = $this->extractBodies($connection, $uid, $structure);

        return new EmailMessage(
            uid: (string) $uid,
            fromAddress: $this->extractEmailAddress($from),
            subject: $subject,
            receivedAt: $date,
            bodyPlain: $plain,
            bodyHtml: $html,
        );
    }

    /**
     * @return array{0: string, 1: string} [plainText, html]
     */
    private function extractBodies(\IMAP\Connection $connection, int $uid, object|false $structure): array
    {
        if ($structure === false) {
            return ['', ''];
        }

        if (!isset($structure->parts) || !is_array($structure->parts)) {
            // Single-part message.
            $body = imap_fetchbody($connection, $uid, '1', FT_UID | FT_PEEK);
            $body = $this->decodePart($body, $structure->encoding ?? 0);
            return ($structure->subtype ?? '') === 'HTML' ? ['', $body] : [$body, ''];
        }

        $plain = '';
        $html = '';
        foreach ($structure->parts as $index => $part) {
            $partNumber = (string) ($index + 1);
            $subtype = strtoupper($part->subtype ?? '');
            if ($subtype !== 'PLAIN' && $subtype !== 'HTML') {
                continue;
            }
            $raw = imap_fetchbody($connection, $uid, $partNumber, FT_UID | FT_PEEK);
            $decoded = $this->decodePart($raw, $part->encoding ?? 0);
            if ($subtype === 'PLAIN') {
                $plain .= $decoded;
            } else {
                $html .= $decoded;
            }
        }

        return [$plain, $html];
    }

    private function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw), // ENCBASE64
            4 => (string) quoted_printable_decode($raw), // ENCQUOTEDPRINTABLE
            default => $raw,
        };
    }

    private function extractEmailAddress(string $fromHeader): string
    {
        if (preg_match('/<([^>]+)>/', $fromHeader, $m) === 1) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($fromHeader));
    }

    public function markProcessed(string $uid): void
    {
        $connection = $this->connect();
        $intUid = (int) $uid;

        imap_setflag_full($connection, (string) $intUid, '\\Seen', ST_UID);

        if (!@imap_mail_move($connection, (string) $intUid, $this->processedFolder, CP_UID)) {
            $this->logger->error('Failed to move message to processed folder', ['uid' => $uid, 'error' => imap_last_error()]);
        }

        if ($this->deleteOnSuccess) {
            imap_setflag_full($connection, (string) $intUid, '\\Deleted', ST_UID);
        }

        imap_expunge($connection);
        $this->logger->info('Message marked processed', ['uid' => $uid, 'deleted' => $this->deleteOnSuccess]);
    }

    public function markFailed(string $uid, string $reason): void
    {
        $connection = $this->connect();
        $intUid = (int) $uid;

        imap_setflag_full($connection, (string) $intUid, '\\Seen', ST_UID);

        if (!@imap_mail_move($connection, (string) $intUid, $this->failedFolder, CP_UID)) {
            $this->logger->error('Failed to move message to failed folder', ['uid' => $uid, 'error' => imap_last_error()]);
        }

        imap_expunge($connection);
        $this->logger->warning('Message marked failed', ['uid' => $uid, 'reason' => $reason]);
    }

    public function disconnect(): void
    {
        if ($this->connection !== false) {
            imap_close($this->connection);
            $this->connection = false;
        }
    }
}
