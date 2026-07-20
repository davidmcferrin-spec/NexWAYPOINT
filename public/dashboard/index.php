<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
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

$statusErrors = [];
$statusMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['form'] ?? '') === 'status_override') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $statusErrors[] = 'Your session expired. Please resubmit.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'set') {
                $status = (string) ($_POST['status'] ?? '');
                $expiresOn = (string) ($_POST['expires_on'] ?? '');
                $note = trim((string) ($_POST['note'] ?? ''));
                $tripRepo->setStatusOverride(
                    $user->id,
                    $status,
                    $note !== '' ? $note : null,
                    $expiresOn,
                    $user->id,
                );
                $statusMessage = 'Status override saved until ' . $expiresOn . '.';
            } elseif ($action === 'clear') {
                $tripRepo->clearStatusOverride($user->id, $user->id);
                $statusMessage = 'Manual status override cleared.';
            } else {
                throw new InvalidArgumentException('Unknown action.');
            }
        } catch (Throwable $e) {
            $statusErrors[] = $e->getMessage();
        }
    }
}

$myStatus = $statusEngine->resolveForUser($user->id);
$myOverride = $tripRepo->activeStatusOverride($user->id);
$myUpcomingTrips = $tripRepo->findActiveOrUpcoming($user->id, 60);
$unreadCount = $notifications->unreadCount($user->id);

$todayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');
$defaultExpires = $myOverride['expires_on']
    ?? $myOverride['effective_date']
    ?? $todayYmd;
$formStatus = (string) ($myOverride['status'] ?? 'home');
$formNote = (string) ($myOverride['note'] ?? '');
$overrideActive = $myOverride !== null;
$travelOverridesManual = $overrideActive
    && !in_array($myStatus['status'], ['home', 'office', 'remote', 'unavailable'], true);

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
        <p>
            <span class="badge <?= statusBadgeClass($myStatus['status']) ?>"><?= htmlspecialchars($myStatus['label'], ENT_QUOTES) ?></span>
            <?php if ($overrideActive && !$travelOverridesManual): ?>
                <span class="hint">
                    · Manual until <?= htmlspecialchars((string) ($myOverride['expires_on'] ?? $myOverride['effective_date']), ENT_QUOTES) ?>
                </span>
            <?php elseif ($travelOverridesManual): ?>
                <span class="hint">
                    · Travel is showing now; your manual override resumes after travel
                    (through <?= htmlspecialchars((string) ($myOverride['expires_on'] ?? $myOverride['effective_date']), ENT_QUOTES) ?>)
                </span>
            <?php endif; ?>
        </p>
        <?php if (!empty($myStatus['detail']['note'])): ?>
            <p class="hint"><?= htmlspecialchars((string) $myStatus['detail']['note'], ENT_QUOTES) ?></p>
        <?php endif; ?>

        <?php foreach ($statusErrors as $err): ?>
            <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
        <?php endforeach; ?>
        <?php if ($statusMessage !== null): ?>
            <p class="alert alert-success"><?= htmlspecialchars($statusMessage, ENT_QUOTES) ?></p>
        <?php endif; ?>

        <form method="post" class="stack status-override-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="form" value="status_override">
            <div class="status-override-row">
                <label>Set status
                    <select name="status" required>
                        <?php foreach ([
                            'home' => 'Home',
                            'office' => 'Office',
                            'remote' => 'Working remote',
                            'unavailable' => 'Unavailable',
                        ] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $formStatus === $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Until
                    <input type="date" name="expires_on" required
                        min="<?= htmlspecialchars($todayYmd, ENT_QUOTES) ?>"
                        value="<?= htmlspecialchars((string) $defaultExpires, ENT_QUOTES) ?>">
                </label>
            </div>
            <label>Note (optional)
                <input type="text" name="note" maxlength="255"
                    value="<?= htmlspecialchars($formNote, ENT_QUOTES) ?>"
                    placeholder="e.g. WFH while waiting on parts">
            </label>
            <div class="modal-actions" style="margin:0">
                <button type="submit" class="primary" name="action" value="set">Save override</button>
                <?php if ($overrideActive): ?>
                    <button type="submit" class="secondary" name="action" value="clear">Clear override</button>
                <?php endif; ?>
            </div>
            <p class="hint">
                Temporary override for the team board. Active travel (in flight, layover, hotel)
                still takes priority while you are on the road.
            </p>
        </form>

        <?php if ($unreadCount > 0): ?>
            <p>
                <a href="/alerts/index.php"><?= $unreadCount ?> unread alert<?= $unreadCount === 1 ? '' : 's' ?></a>
                — email imports and flight status changes.
            </p>
        <?php endif; ?>
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

    <h2>Your upcoming trips <a class="hint" href="/trips/list.php" style="font-weight: 400; font-size: 0.85rem;">View all</a></h2>
    <?php if ($myUpcomingTrips === []): ?>
        <p class="empty-state">Nothing on the books. <a href="/flights/add.php">Add a flight</a>, <a href="/trains/add.php">add a train</a>, <a href="/trips/list.php">review past trips</a>, or <a href="/hotels/add.php">log a hotel stay</a>.</p>
    <?php else: ?>
        <?php foreach ($myUpcomingTrips as $trip): ?>
            <div class="card">
                <h3>
                    <a href="/trips/view.php?id=<?= (int) $trip->id ?>">
                        <?= htmlspecialchars($trip->destinationCity, ENT_QUOTES) ?>
                    </a>
                    <?php if ($trip->isPrivate): ?><span class="badge badge-blacklist">Private</span><?php endif; ?>
                </h3>
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
