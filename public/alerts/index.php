<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Trips\NotificationRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$repo = new NotificationRepository($app['db']);

$errors = [];
$message = null;
$filter = (string) ($_GET['filter'] ?? 'unread');
if (!in_array($filter, ['unread', 'all'], true)) {
    $filter = 'unread';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'mark_read') {
                $id = (int) ($_POST['notification_id'] ?? 0);
                if ($id < 1 || !$repo->markReadForUser($user->id, $id)) {
                    throw new InvalidArgumentException('Alert not found or already read.');
                }
                $message = 'Alert marked as read.';
            } elseif ($action === 'mark_all_read') {
                $n = $repo->markAllReadForUser($user->id);
                $message = $n === 0
                    ? 'No unread alerts.'
                    : ($n === 1 ? '1 alert marked as read.' : "{$n} alerts marked as read.");
            } else {
                throw new InvalidArgumentException('Unknown action.');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$unreadCount = $repo->unreadCount($user->id);
$alerts = $repo->findForUser($user->id, $filter === 'unread', 100);

$alertTypeLabel = static function (string $type): string {
    return match ($type) {
        'delay' => 'Flight delay',
        'gate_change' => 'Gate change',
        'cancellation' => 'Flight cancelled',
        'diversion' => 'Flight diverted',
        'landed' => 'Landed',
        'hotel_import' => 'Hotel import',
        'trip_import' => 'Trip import',
        default => ucwords(str_replace('_', ' ', $type)),
    };
};

$alertActionHref = static function (array $alert): ?array {
    $type = (string) ($alert['alert_type'] ?? '');
    $tripId = isset($alert['trip_id']) && $alert['trip_id'] !== null && $alert['trip_id'] !== ''
        ? (int) $alert['trip_id']
        : null;
    if ($tripId !== null && $tripId > 0) {
        return ['href' => '/trips/view.php?id=' . $tripId, 'label' => 'View trip'];
    }
    if ($type === 'hotel_import') {
        return ['href' => '/hotels/list.php', 'label' => 'Hotel stays'];
    }
    if ($type === 'trip_import') {
        return ['href' => '/trips/list.php', 'label' => 'Trips'];
    }
    return null;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Alerts</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <div class="modal-actions" style="justify-content:space-between;align-items:center;margin-bottom:0.5rem">
        <h1 style="margin:0">Alerts</h1>
        <?php if ($unreadCount > 0): ?>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="secondary">Mark all <?= $unreadCount ?> as read</button>
            </form>
        <?php endif; ?>
    </div>
    <p class="hint">
        Travel notices from email imports and flight status checks.
        Marking an alert read clears it from the unread count in the header.
    </p>

    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <p class="alerts-filter">
        <a class="<?= $filter === 'unread' ? 'is-active' : '' ?>" href="/alerts/index.php?filter=unread">
            Unread<?= $unreadCount > 0 ? ' (' . $unreadCount . ')' : '' ?>
        </a>
        ·
        <a class="<?= $filter === 'all' ? 'is-active' : '' ?>" href="/alerts/index.php?filter=all">All</a>
    </p>

    <?php if ($alerts === []): ?>
        <p class="empty-state">
            <?php if ($filter === 'unread'): ?>
                No unread alerts.
                <a href="/alerts/index.php?filter=all">View all alerts</a>
            <?php else: ?>
                No alerts yet. Imported bookings and flight delays will show up here.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <ul class="alert-list">
            <?php foreach ($alerts as $alert): ?>
                <?php
                $isRead = !empty($alert['is_read']);
                $action = $alertActionHref($alert);
                $type = (string) ($alert['alert_type'] ?? 'notice');
                ?>
                <li class="card alert-item<?= $isRead ? ' is-read' : '' ?>">
                    <div class="alert-item-header">
                        <span class="badge <?= match ($type) {
                            'delay', 'gate_change', 'diversion' => 'badge-status-delay',
                            'cancellation' => 'badge-blacklist',
                            'landed' => 'badge-status-home',
                            default => 'badge-status-travel',
                        } ?>"><?= htmlspecialchars($alertTypeLabel($type), ENT_QUOTES) ?></span>
                        <span class="hint"><?= htmlspecialchars((string) ($alert['created_at'] ?? ''), ENT_QUOTES) ?></span>
                        <?php if ($isRead): ?>
                            <span class="hint">Read</span>
                        <?php endif; ?>
                    </div>
                    <p class="alert-item-message"><?= htmlspecialchars((string) ($alert['message'] ?? ''), ENT_QUOTES) ?></p>
                    <div class="alert-item-actions">
                        <?php if ($action !== null): ?>
                            <a href="<?= htmlspecialchars($action['href'], ENT_QUOTES) ?>"><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></a>
                        <?php endif; ?>
                        <?php if (!$isRead): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= (int) $alert['id'] ?>">
                                <button type="submit" class="secondary">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
