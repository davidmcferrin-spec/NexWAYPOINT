<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$tripRepo = new TripRepository($app['db'], $app['logger']);
$carrierRepo = new CarrierRepository($app['db'], $app['logger']);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$trip = $tripRepo->find($id);

if ($trip === null || $trip->ownerId !== $user->id) {
    http_response_code(404);
    echo 'Trip not found.';
    exit;
}

$message = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'toggle_private') {
            $tripRepo->setPrivacy((int) $trip->id, !$trip->isPrivate, $user->id);
            $trip = $tripRepo->find((int) $trip->id) ?? $trip;
            $message = $trip->isPrivate ? 'Trip marked private.' : 'Trip is visible per your sharing settings.';
        } elseif ($action === 'mark_completed' && in_array($trip->status, ['planned', 'active'], true)) {
            $trip = $tripRepo->update(new Trip(
                id: $trip->id,
                ownerId: $trip->ownerId,
                destinationCity: $trip->destinationCity,
                startDate: $trip->startDate,
                endDate: $trip->endDate,
                status: 'completed',
                tripPurpose: $trip->tripPurpose,
                notes: $trip->notes,
                isPrivate: $trip->isPrivate,
            ), $user->id);
            $message = 'Trip marked completed.';
        }
    }
}

$segments = $tripRepo->segmentsForTrip((int) $trip->id);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; <?= htmlspecialchars($trip->destinationCity, ENT_QUOTES) ?></title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <p><a href="/trips/list.php">&larr; All trips</a></p>
    <h1>
        <?= htmlspecialchars($trip->destinationCity, ENT_QUOTES) ?>
        <?php if ($trip->isPrivate): ?>
            <span class="badge badge-blacklist">Private</span>
        <?php endif; ?>
    </h1>
    <p class="hint">
        <?= htmlspecialchars($trip->startDate, ENT_QUOTES) ?>
        &rarr;
        <?= htmlspecialchars($trip->endDate, ENT_QUOTES) ?>
        ·
        <span class="badge"><?= htmlspecialchars($trip->status, ENT_QUOTES) ?></span>
        <?php if ($trip->endDate < $today && $trip->status !== 'cancelled' && $trip->status !== 'completed'): ?>
            · ended
        <?php endif; ?>
    </p>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if ($trip->tripPurpose !== null || $trip->notes !== null): ?>
        <div class="card">
            <?php if ($trip->tripPurpose !== null): ?>
                <p><strong>Purpose:</strong> <?= htmlspecialchars($trip->tripPurpose, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($trip->notes !== null): ?>
                <p><?= nl2br(htmlspecialchars($trip->notes, ENT_QUOTES)) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Segments</h2>
    <?php if ($segments === []): ?>
        <p class="empty-state">No flight or train segments on this trip.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Carrier / number</th>
                    <th>Route</th>
                    <th>Depart</th>
                    <th>Arrive</th>
                    <th>Confirmation</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($segments as $segment): ?>
                    <?php
                    $carrierLabel = $segment->carrier;
                    if ($segment->carrierId !== null) {
                        $linked = $carrierRepo->find($segment->carrierId);
                        if ($linked !== null) {
                            $carrierLabel = $linked->name;
                            if ($segment->flightNumber !== null && $segment->flightNumber !== '') {
                                $ident = $linked->flightIdent($segment->flightNumber);
                                if ($ident !== null) {
                                    $carrierLabel .= ' ' . $ident;
                                } else {
                                    $carrierLabel .= ' ' . $segment->flightNumber;
                                }
                            }
                        }
                    } elseif ($segment->flightNumber !== null && $segment->flightNumber !== '') {
                        $carrierLabel = trim(($carrierLabel ?? '') . ' ' . $segment->flightNumber);
                    }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($segment->segmentType, ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($carrierLabel !== null && $carrierLabel !== '' ? $carrierLabel : '—', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars(($segment->origin ?? '?') . ' → ' . ($segment->destination ?? '?'), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($segment->departDt ?? '—', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($segment->arriveDt ?? '—', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($segment->confirmationCode ?? '—', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($segment->status, ENT_QUOTES) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="card" style="margin-top: 1.5rem;">
        <h3>Actions</h3>
        <?php if (in_array($trip->status, ['planned', 'active'], true)): ?>
            <p style="margin-bottom: 0.75rem;">
                <a class="primary" href="/trips/builder.php?id=<?= (int) $trip->id ?>"
                    style="display:inline-block;padding:0.6rem 1.2rem;background:var(--accent);color:var(--accent-contrast);border-radius:4px;text-decoration:none;">
                    Edit trip &amp; flight legs
                </a>
            </p>
        <?php endif; ?>
        <form method="post" style="display: inline-block; margin-right: 0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="toggle_private">
            <button type="submit"><?= $trip->isPrivate ? 'Make visible to sharing rules' : 'Mark private' ?></button>
        </form>
        <?php if (in_array($trip->status, ['planned', 'active'], true)): ?>
            <form method="post" style="display: inline-block;"
                  onsubmit="return confirm('Mark this trip completed?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="mark_completed">
                <button type="submit">Mark completed</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
