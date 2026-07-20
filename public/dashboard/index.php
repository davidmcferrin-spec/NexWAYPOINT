<?php

declare(strict_types=1);

use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$db = $app['db'];
$logger = $app['logger'];

$userRepo = new UserRepository($db, $logger);
$tripRepo = new TripRepository($db, $logger);
$carrierRepo = new CarrierRepository($db, $logger);
$statusEngine = new TripStatusEngine($tripRepo, $logger);
$visibilityEngine = new VisibilityEngine($userRepo, new VisibilityRuleRepository($db));
$blockRepo = new VisibilityBlockRepository($db);
$notifications = new NotificationRepository($db);

$myStatus = $statusEngine->resolveForUser($user->id);
$myUpcomingTrips = $tripRepo->findActiveOrUpcoming($user->id, 60);
$unreadCount = $notifications->unreadCount($user->id);

/**
 * Manual work-state statuses (home/office/remote/unavailable) are always
 * shown team-wide -- they aren't trip data and aren't covered by the
 * visibility_rules field list. Trip-derived detail (destination city,
 * carrier, etc.) IS gated per VisibilityEngine, per-viewer. Private trips
 * and per-user hide lists suppress travel detail entirely.
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
        $hidden = $trip !== null && $blockRepo->isHiddenFromViewer(
            $teammate->id,
            $user->id,
            $trip->isPrivate,
            VisibilityBlockRepository::TYPE_TRIP,
            $trip->id
        );

        if ($hidden) {
            $displayLabel = match ($status['status']) {
                'en_route', 'layover', 'delayed', 'at_hotel' => 'Traveling',
                'cancelled' => 'Travel disrupted',
                default => 'Unavailable',
            };
        } else {
            $tripIsPrivate = $trip !== null && $trip->isPrivate;
            $visibility = $visibilityEngine->getVisibleFields($user->id, $teammate->id, $tripIsPrivate);

            if (!in_array('destination_city', $visibility['visible_fields'], true)) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Dashboard</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
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
        <p class="empty-state">Nothing on the books. <a href="/flights/add.php">Add a flight</a> or <a href="/hotels/add.php">log a hotel stay</a>.</p>
    <?php else: ?>
        <?php foreach ($myUpcomingTrips as $trip): ?>
            <div class="card">
                <h3><?= htmlspecialchars($trip->destinationCity, ENT_QUOTES) ?> <?php if ($trip->isPrivate): ?><span class="badge badge-blacklist">Private</span><?php endif; ?></h3>
                <p><?= htmlspecialchars($trip->startDate, ENT_QUOTES) ?> &rarr; <?= htmlspecialchars($trip->endDate, ENT_QUOTES) ?></p>
                <?php if ($trip->tripPurpose !== null): ?><p><?= htmlspecialchars($trip->tripPurpose, ENT_QUOTES) ?></p><?php endif; ?>
                <?php
                $segments = $tripRepo->segmentsForTrip((int) $trip->id);
                foreach ($segments as $segment):
                    if ($segment->segmentType !== 'flight') {
                        continue;
                    }
                    ?>
                    <p>
                        <?= htmlspecialchars(trim(($segment->carrier ?? '') . ' ' . ($segment->flightNumber ?? '')), ENT_QUOTES) ?>
                        <?php
                        if ($segment->carrierId !== null) {
                            $linked = $carrierRepo->find($segment->carrierId);
                            if ($linked !== null && $linked->iataCode !== null) {
                                $ident = $linked->flightIdent((string) ($segment->flightNumber ?? ''));
                                if ($ident !== null) {
                                    echo ' <span class="hint">(' . htmlspecialchars($ident, ENT_QUOTES) . ')</span>';
                                }
                            }
                        }
                        ?>
                        · <?= htmlspecialchars(($segment->origin ?? '?') . ' → ' . ($segment->destination ?? '?'), ENT_QUOTES) ?>
                        <?php if ($segment->departDt !== null): ?>
                            · <?= htmlspecialchars($segment->departDt, ENT_QUOTES) ?>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
