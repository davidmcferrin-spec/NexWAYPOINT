<?php

declare(strict_types=1);

use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$db = $app['db'];
$logger = $app['logger'];

$userRepo = new UserRepository($db, $logger);
$tripRepo = new TripRepository($db, $logger);
$statusEngine = new TripStatusEngine($tripRepo, $logger);
$visibilityEngine = new VisibilityEngine($userRepo, new VisibilityRuleRepository($db));
$notifications = new NotificationRepository($db);

$myStatus = $statusEngine->resolveForUser($user->id);
$myUpcomingTrips = $tripRepo->findActiveOrUpcoming($user->id, 60);
$unreadCount = $notifications->unreadCount($user->id);

/**
 * Manual work-state statuses (home/office/remote/unavailable) are always
 * shown team-wide -- they aren't trip data and aren't covered by the
 * visibility_rules field list. Trip-derived detail (destination city,
 * carrier, etc.) IS gated per VisibilityEngine, per-viewer.
 */
$alwaysVisibleStatuses = ['home', 'office', 'remote', 'unavailable'];

$team = [];
foreach ($userRepo->findAllActive() as $teammate) {
    if ($teammate->id === $user->id) {
        continue;
    }

    $status = $statusEngine->resolveForUser($teammate->id);
    $tripId = $status['detail']['trip_id'] ?? null;

    $displayLabel = $status['label'];
    if (!in_array($status['status'], $alwaysVisibleStatuses, true) && $tripId !== null) {
        $trip = $tripRepo->find((int) $tripId);
        $tripIsPrivate = $trip !== null && $trip->isPrivate;
        $visibility = $visibilityEngine->getVisibleFields($user->id, $teammate->id, $tripIsPrivate);

        if (!in_array('destination_city', $visibility['visible_fields'], true)) {
            // Collapse to a status-only label with no city/route detail.
            $displayLabel = match ($status['status']) {
                'en_route' => 'Traveling',
                'layover' => 'Traveling (layover)',
                'delayed' => 'Traveling (delayed)',
                'at_hotel' => 'Traveling',
                'cancelled' => 'Travel disrupted',
                default => $status['label'],
            };
        }
    }

    $team[] = ['user' => $teammate, 'status' => $status['status'], 'label' => $displayLabel];
}

$travelingCount = count(array_filter($team, static fn (array $t) => !in_array($t['status'], $alwaysVisibleStatuses, true)));

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'home', 'office' => 'badge-status-home',
        'delayed', 'cancelled' => 'badge-status-delay',
        default => 'badge-status-travel',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexWAYPOINT &middot; Dashboard</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div>
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/list.php">Hotels</a>
        <a href="/hotels/add.php">+ Log a stay</a>
        <a href="/settings/visibility.php">Sharing</a>
        <a href="/logout.php">Sign out (<?= htmlspecialchars($user->displayName, ENT_QUOTES) ?>) <?php if ($unreadCount > 0): ?>&middot; <?= $unreadCount ?> new<?php endif; ?></a>
    </div>
</nav>
<main class="container">
    <div class="card">
        <h3>Your status</h3>
        <p><span class="badge <?= statusBadgeClass($myStatus['status']) ?>"><?= htmlspecialchars($myStatus['label'], ENT_QUOTES) ?></span></p>
    </div>

    <h1>Who's traveling this week</h1>
    <p><?= $travelingCount ?> of <?= count($team) ?> teammates currently traveling.</p>

    <?php if ($team === []): ?>
        <p class="empty-state">No other active teammates yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Teammate</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($team as $entry): ?>
                    <tr>
                        <td><?= htmlspecialchars($entry['user']->displayName, ENT_QUOTES) ?></td>
                        <td><span class="badge <?= statusBadgeClass($entry['status']) ?>"><?= htmlspecialchars($entry['label'], ENT_QUOTES) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Your upcoming trips</h2>
    <?php if ($myUpcomingTrips === []): ?>
        <p class="empty-state">Nothing on the books. <a href="/hotels/add.php">Log a hotel stay</a> or add a trip.</p>
    <?php else: ?>
        <?php foreach ($myUpcomingTrips as $trip): ?>
            <div class="card">
                <h3><?= htmlspecialchars($trip->destinationCity, ENT_QUOTES) ?> <?php if ($trip->isPrivate): ?><span class="badge badge-blacklist">Private</span><?php endif; ?></h3>
                <p><?= htmlspecialchars($trip->startDate, ENT_QUOTES) ?> &rarr; <?= htmlspecialchars($trip->endDate, ENT_QUOTES) ?></p>
                <?php if ($trip->tripPurpose !== null): ?><p><?= htmlspecialchars($trip->tripPurpose, ENT_QUOTES) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
