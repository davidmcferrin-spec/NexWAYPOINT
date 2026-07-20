<?php

declare(strict_types=1);

use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\TripRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$tripRepo = new TripRepository($app['db'], $app['logger']);
$carrierRepo = new CarrierRepository($app['db'], $app['logger']);

$scope = (string) ($_GET['scope'] ?? 'all');
if (!in_array($scope, ['all', 'upcoming', 'past', 'cancelled'], true)) {
    $scope = 'all';
}

$trips = $tripRepo->searchForOwner($user->id, $scope);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$scopeLabel = match ($scope) {
    'upcoming' => 'Upcoming',
    'past' => 'Past',
    'cancelled' => 'Cancelled',
    default => 'All',
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'active' => 'badge-status-travel',
        'planned' => 'badge-status-home',
        'completed' => 'badge-status-home',
        'cancelled' => 'badge-blacklist',
        default => 'badge-status-travel',
    };
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Trips</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <div class="card-header-row" style="margin-bottom: 1rem;">
        <div>
            <h1 style="margin: 0;">Trips</h1>
            <p class="hint" style="margin: 0.35rem 0 0;">Itineraries from email import and manual flight/train entries.</p>
        </div>
        <div>
            <a class="primary" href="/flights/add.php" style="text-decoration: none; display: inline-block; padding: 0.45rem 0.85rem;">Add flight</a>
            <a href="/trains/add.php" style="margin-left: 0.5rem;">Add train</a>
        </div>
    </div>

    <nav class="settings-nav" aria-label="Trip filters">
        <?php foreach (['all' => 'All', 'upcoming' => 'Upcoming', 'past' => 'Past', 'cancelled' => 'Cancelled'] as $key => $label): ?>
            <a class="<?= $scope === $key ? 'settings-nav-link is-active' : 'settings-nav-link' ?>"
               href="/trips/list.php?scope=<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($trips === []): ?>
        <p class="empty-state">
            No <?= htmlspecialchars(strtolower($scopeLabel), ENT_QUOTES) ?> trips yet.
            <?php if ($scope === 'all'): ?>
                <a href="/flights/add.php">Add a flight</a> or forward a confirmation to your mail inbox.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Destination</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Segments</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trips as $trip): ?>
                    <?php
                    $segments = $tripRepo->segmentsForTrip((int) $trip->id);
                    $summaryParts = [];
                    foreach (array_slice($segments, 0, 3) as $segment) {
                        $label = strtoupper($segment->segmentType);
                        $route = trim(($segment->origin ?? '?') . '→' . ($segment->destination ?? '?'));
                        $flight = trim(($segment->carrier ?? '') . ' ' . ($segment->flightNumber ?? ''));
                        $summaryParts[] = trim($label . ' ' . ($flight !== '' ? $flight . ' ' : '') . $route);
                    }
                    if (count($segments) > 3) {
                        $summaryParts[] = '+' . (count($segments) - 3) . ' more';
                    }
                    $isPast = $trip->endDate < $today && $trip->status !== 'cancelled';
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($trip->destinationCity, ENT_QUOTES) ?>
                            <?php if ($trip->isPrivate): ?>
                                <span class="badge badge-blacklist">Private</span>
                            <?php endif; ?>
                            <?php if ($trip->tripPurpose): ?>
                                <div class="hint"><?= htmlspecialchars($trip->tripPurpose, ENT_QUOTES) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($trip->startDate, ENT_QUOTES) ?>
                            &rarr;
                            <?= htmlspecialchars($trip->endDate, ENT_QUOTES) ?>
                            <?php if ($isPast && $trip->status === 'planned'): ?>
                                <div class="hint">Ended</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $statusBadge($trip->status) ?>">
                                <?= htmlspecialchars($trip->status, ENT_QUOTES) ?>
                            </span>
                        </td>
                        <td class="hint">
                            <?= $summaryParts === []
                                ? 'No segments'
                                : htmlspecialchars(implode(' · ', $summaryParts), ENT_QUOTES) ?>
                        </td>
                        <td><a href="/trips/view.php?id=<?= (int) $trip->id ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
