<?php

declare(strict_types=1);

/**
 * System-admin (is_system) only: recent mail imports with links to created
 * travel and optional raw .eml download while within retention.
 */

use NexWaypoint\Core\Env;
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
        content, not IMAP or forward Date/Sent headers. Download a failure to add a fixture under
        <code>tests/</code> and widen the parser.
    </p>

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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
