<?php

declare(strict_types=1);

/**
 * System-admin (is_system) only: recent mail imports with links to created
 * travel, optional raw .eml download, force re-parse (updates trips/stays by
 * confirmation), and re-queue of IMAP failures.
 */

use NexWaypoint\Core\Csrf;
use NexWaypoint\Core\Env;
use NexWaypoint\Mail\DreamHostImapSource;
use NexWaypoint\Mail\MailPollerFactory;
use NexWaypoint\Mail\NullMailSource;
use NexWaypoint\Mail\ParseLogRepository;
use NexWaypoint\Mail\RawMailStore;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

if (!$user->isSystem) {
    http_response_code(403);
    echo 'Forbidden. Mail review is limited to the system admin account.';
    exit;
}

$reviewDays = max(1, (int) Env::get('MAIL_REVIEW_DAYS', '14'));
$retentionDays = max(1, (int) Env::get('MAIL_RAW_RETENTION_DAYS', '7'));
$parseLog = new ParseLogRepository($app['db']);
$rawStore = new RawMailStore(
    NEXWAYPOINT_ROOT . '/storage/mail_raw',
    $retentionDays,
    $app['logger'],
);

$errors = [];
$message = null;
$sourceName = Env::get('MAIL_SOURCE', 'dreamhost_imap');
$imapConfigured = $sourceName === 'dreamhost_imap'
    && Env::get('IMAP_HOST') !== null
    && Env::get('IMAP_HOST') !== ''
    && Env::get('IMAP_USERNAME') !== null
    && Env::get('IMAP_USERNAME') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $logId = (int) ($_POST['parse_log_id'] ?? 0);
        $row = $logId > 0 ? $parseLog->find($logId) : null;
        if ($row === null) {
            $errors[] = 'Parse log row not found.';
        } else {
            $status = (string) ($row['parse_status'] ?? '');
            $mailUid = (string) ($row['mail_uid'] ?? '');
            $subject = (string) ($row['subject'] ?? '');
            $fromAddress = (string) ($row['from_address'] ?? '');
            try {
                if ($action === 'reparse') {
                    if (!in_array($status, ['failed', 'success'], true)) {
                        throw new \RuntimeException('Only failed or successful imports with a stored .eml can be re-parsed.');
                    }
                    $rawPath = isset($row['raw_path']) ? (string) $row['raw_path'] : null;
                    $rawExpires = isset($row['raw_expires_at']) ? (string) $row['raw_expires_at'] : null;
                    if ($rawPath === null || $rawPath === '' || $rawStore->isExpired($rawExpires)) {
                        throw new \RuntimeException('Raw .eml is missing or expired; use Re-queue instead if the message is still in ParseFailed.');
                    }
                    $absolute = $rawStore->absolutePath($rawPath);
                    if ($absolute === null) {
                        throw new \RuntimeException('Raw .eml file not found on disk.');
                    }
                    $email = $rawStore->loadEmailMessage($absolute);
                    if ($email === null) {
                        throw new \RuntimeException('Could not read stored .eml into a message.');
                    }

                    $poller = MailPollerFactory::create($app, new NullMailSource(), (string) ($row['source'] ?? $sourceName));
                    // Force: bypass parse_log short-circuit so confirmation upsert replaces legs/stay.
                    $result = $poller->reprocessMessage($email, true);
                    if (!$result['ok']) {
                        throw new \RuntimeException($result['reason'] ?? 'Re-parse failed.');
                    }

                    if ($status === 'failed' && $imapConfigured && ctype_digit($mailUid)) {
                        try {
                            $imap = new DreamHostImapSource($app['logger']);
                            $imap->markProcessedFromFailedFolder($mailUid, $subject, $fromAddress);
                        } catch (\Throwable $e) {
                            $app['logger']->warning('Re-parse succeeded but IMAP finalize failed', [
                                'uid' => $mailUid,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    $message = 'Re-parse succeeded for UID ' . $mailUid
                        . '. Matching trips/stays were upserted by confirmation code.';
                } elseif ($action === 'requeue') {
                    if ($status !== 'failed') {
                        throw new \RuntimeException('Only failed imports can be re-queued to INBOX.');
                    }
                    if (!$imapConfigured) {
                        throw new \RuntimeException('IMAP re-queue requires MAIL_SOURCE=dreamhost_imap with IMAP credentials configured.');
                    }
                    if ($mailUid === '' || !ctype_digit($mailUid)) {
                        throw new \RuntimeException('Cannot re-queue: mail UID is missing or not a numeric IMAP UID.');
                    }
                    $imap = new DreamHostImapSource($app['logger']);
                    $imap->requeueFailedToInbox($mailUid, $subject, $fromAddress);
                    $message = 'Moved UID ' . $mailUid . ' back to INBOX (unread). The next mail poll will retry it.';
                } else {
                    $errors[] = 'Unknown action.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$rows = $parseLog->findRecent($reviewDays, 250);
$settingsSection = 'mail-review';

$statusClass = static function (string $status): string {
    return match ($status) {
        'success' => 'badge-status-home',
        'failed' => 'badge-status-delay',
        'ignored' => 'badge-status-travel',
        default => 'badge-status-travel',
    };
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Mail review</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container container-wide">
    <?php require __DIR__ . '/_settings_nav.php'; ?>

    <h1>Mail import review</h1>
    <p class="hint">
        System admin only. Showing the last <?= (int) $reviewDays ?> days of inbound parses
        (<code>MAIL_REVIEW_DAYS</code>). Raw .eml files are kept for <?= (int) $retentionDays ?> days
        (<code>MAIL_RAW_RETENTION_DAYS</code>) then deleted. Travel dates are taken from confirmation
        content, not IMAP or forward Date/Sent headers.
        <strong>Re-parse</strong> (failed or success, while .eml retained) re-runs current parsers and
        upserts by confirmation/PNR so incomplete trips get replaced with full legs;
        <strong>Re-queue</strong> (failures only) moves the message from <code>ParseFailed</code>
        back to INBOX unread for the next cron poll. Gmail, Outlook/Exchange, Proton, Apple,
        and direct vendor mail are normalized the same way as a normal poll.
    </p>

    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <?php if ($rows === []): ?>
        <p class="empty-state">No parse log rows in this window.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Received</th>
                        <th>From</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Created travel</th>
                        <th>Raw</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $status = (string) ($row['parse_status'] ?? '');
                        $tripId = isset($row['trip_id']) && $row['trip_id'] !== null ? (int) $row['trip_id'] : 0;
                        $stayId = isset($row['hotel_stay_id']) && $row['hotel_stay_id'] !== null ? (int) $row['hotel_stay_id'] : 0;
                        $rawPath = isset($row['raw_path']) ? (string) $row['raw_path'] : null;
                        $rawExpires = isset($row['raw_expires_at']) ? (string) $row['raw_expires_at'] : null;
                        $canDownload = $rawPath !== null && $rawPath !== ''
                            && !$rawStore->isExpired($rawExpires)
                            && $rawStore->absolutePath($rawPath) !== null;
                        $canReparse = in_array($status, ['failed', 'success'], true) && $canDownload;
                        $canRequeue = $status === 'failed' && $imapConfigured
                            && ctype_digit((string) ($row['mail_uid'] ?? ''));
                        $reason = trim((string) ($row['failure_reason'] ?? ''));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['received_at'] ?? ''), ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string) ($row['from_address'] ?? ''), ENT_QUOTES) ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($row['subject'] ?? ''), ENT_QUOTES) ?>
                                <?php if ($reason !== ''): ?>
                                    <div class="hint"><?= htmlspecialchars($reason, ENT_QUOTES) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= $statusClass($status) ?>"><?= htmlspecialchars($status, ENT_QUOTES) ?></span></td>
                            <td><?= htmlspecialchars((string) ($row['detected_type'] ?? '—'), ENT_QUOTES) ?></td>
                            <td>
                                <?php if ($tripId > 0): ?>
                                    <a href="/trips/view.php?id=<?= $tripId ?>">Trip #<?= $tripId ?></a>
                                <?php endif; ?>
                                <?php if ($stayId > 0): ?>
                                    <?php if ($tripId > 0): ?><br><?php endif; ?>
                                    <a href="/hotels/view.php?id=<?= $stayId ?>">Stay #<?= $stayId ?></a>
                                <?php endif; ?>
                                <?php if ($tripId <= 0 && $stayId <= 0): ?>
                                    <span class="hint">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canDownload): ?>
                                    <a href="/settings/mail-raw.php?id=<?= (int) $row['id'] ?>">Download .eml</a>
                                <?php else: ?>
                                    <span class="hint">expired / none</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canReparse || $canRequeue): ?>
                                    <div class="stack" style="gap:0.35rem">
                                        <?php if ($canReparse): ?>
                                            <form method="post" style="display:inline;margin:0">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                                <input type="hidden" name="action" value="reparse">
                                                <input type="hidden" name="parse_log_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="primary">Re-parse</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canRequeue): ?>
                                            <form method="post" style="display:inline;margin:0"
                                                  onsubmit="return confirm('Move this message from ParseFailed back to INBOX unread?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                                <input type="hidden" name="action" value="requeue">
                                                <input type="hidden" name="parse_log_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit">Re-queue</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="hint">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
