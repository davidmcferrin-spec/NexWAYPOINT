<?php

declare(strict_types=1);

use NexWaypoint\Core\CronRunRepository;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$userRepo = new UserRepository($app['db'], $app['logger']);

if (!$userRepo->isAdmin($user)) {
    http_response_code(403);
    echo 'Site admin access required.';
    exit;
}

$settingsSection = 'jobs';
$latest = [];
$recent = [];
$tableMissing = !$app['db']->tableExists('cron_job_runs');

if (!$tableMissing) {
    $runs = new CronRunRepository($app['db']);
    $latest = $runs->latestByJob();
    $recent = $runs->recent(40);
}

$statusBadge = static function (string $status): string {
    return match ($status) {
        'ok' => 'badge-status-home',
        'warning' => 'badge-status-delay',
        'failed' => 'badge-blacklist',
        'running' => 'badge-status-travel',
        default => 'badge-status-travel',
    };
};

$formatSummary = static function (array $summary): string {
    if ($summary === []) {
        return '—';
    }
    $parts = [];
    foreach ($summary as $key => $value) {
        if (is_bool($value)) {
            $parts[] = $key . '=' . ($value ? 'yes' : 'no');
        } else {
            $parts[] = $key . '=' . (string) $value;
        }
    }
    return implode(', ', $parts);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Scheduled jobs</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>Cron / service status</h1>
    <p class="hint">
        Last run status for cron jobs. Counts only — no flight numbers, hotels, emails, or user travel details.
    </p>

    <?php if ($tableMissing): ?>
        <p class="alert alert-error">Run <code>php scripts/migrate.php</code> to create the cron job history table.</p>
    <?php else: ?>
        <h2>Latest by job</h2>
        <table>
            <thead>
                <tr>
                    <th>Job</th>
                    <th>Status</th>
                    <th>Started</th>
                    <th>Finished</th>
                    <th>Summary</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (CronRunRepository::JOB_LABELS as $job => $label): ?>
                    <?php $row = $latest[$job] ?? null; ?>
                    <tr>
                        <td><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                        <?php if ($row === null): ?>
                            <td colspan="4" class="hint">No runs recorded yet</td>
                        <?php else: ?>
                            <td>
                                <span class="badge <?= $statusBadge($row['status']) ?>">
                                    <?= htmlspecialchars($row['status'], ENT_QUOTES) ?>
                                </span>
                                <?php if ($row['error_class']): ?>
                                    <span class="hint"><?= htmlspecialchars((string) $row['error_class'], ENT_QUOTES) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['started_at'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string) ($row['finished_at'] ?? '—'), ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($formatSummary($row['summary']), ENT_QUOTES) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Recent history</h2>
        <?php if ($recent === []): ?>
            <p class="empty-state">No cron runs logged yet. They appear after the next scheduled poll/enrich.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Job</th>
                        <th>Status</th>
                        <th>Summary</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['started_at'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['label'], ENT_QUOTES) ?></td>
                            <td>
                                <span class="badge <?= $statusBadge($row['status']) ?>">
                                    <?= htmlspecialchars($row['status'], ENT_QUOTES) ?>
                                </span>
                            </td>
                            <td>
                                <?= htmlspecialchars($formatSummary($row['summary']), ENT_QUOTES) ?>
                                <?php if ($row['error_class']): ?>
                                    <span class="hint"><?= htmlspecialchars((string) $row['error_class'], ENT_QUOTES) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
