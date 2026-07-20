<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Trips\Carrier;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$userRepo = new UserRepository($app['db'], $app['logger']);
$tripRepo = new TripRepository($app['db'], $app['logger']);
$carrierRepo = new CarrierRepository($app['db'], $app['logger']);
$blockRepo = new VisibilityBlockRepository($app['db']);

$errors = [];
$schemaWarning = null;
$operators = [];

if (!$app['db']->tableExists('carriers')) {
    $schemaWarning = 'Database is missing the carriers table. On the server run: php scripts/migrate.php';
} else {
    try {
        $operators = $carrierRepo->findByType(Carrier::TYPE_RAIL);
    } catch (Throwable $e) {
        $app['logger']->error('Failed loading rail operators', ['error' => $e->getMessage()]);
        $schemaWarning = 'Could not load rail operators. If you just pulled new code, run: php scripts/migrate.php';
    }
}

$otherUsers = array_values(array_filter(
    $userRepo->findAllActive(),
    static fn ($u) => $u->id !== $user->id
));

function nullableTrimTrain(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($schemaWarning !== null) {
        $errors[] = $schemaWarning;
    } elseif (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $carrierId = (int) ($_POST['carrier_id'] ?? 0);
        $trainNumber = nullableTrimTrain($_POST['train_number'] ?? null);
        $origin = nullableTrimTrain($_POST['origin'] ?? null);
        $destination = nullableTrimTrain($_POST['destination'] ?? null);
        $departLocal = nullableTrimTrain($_POST['depart_dt'] ?? null);
        $arriveLocal = nullableTrimTrain($_POST['arrive_dt'] ?? null);
        $confirmation = nullableTrimTrain($_POST['confirmation_code'] ?? null);
        $purpose = nullableTrimTrain($_POST['trip_purpose'] ?? null);
        $notes = nullableTrimTrain($_POST['notes'] ?? null);
        $isPrivate = isset($_POST['is_private']) && $_POST['is_private'] === '1';
        $hideFrom = array_map('intval', $_POST['hide_from'] ?? []);

        $operator = $carrierId > 0 ? $carrierRepo->find($carrierId) : null;
        // Carriers are a site-wide catalog; user_id is audit-only, not ownership.
        if ($operator === null || !$operator->isRail()) {
            $errors[] = 'Select a rail operator (or Add New).';
        }
        if ($trainNumber === null) {
            $errors[] = 'Train number is required.';
        }
        if ($origin === null) {
            $errors[] = 'Origin station/city is required.';
        }
        if ($destination === null) {
            $errors[] = 'Destination station/city is required.';
        }
        if ($departLocal === null) {
            $errors[] = 'Departure date/time is required.';
        }

        $departDt = null;
        $arriveDt = null;
        if ($departLocal !== null) {
            $departDt = str_replace('T', ' ', $departLocal);
            if (strlen($departDt) === 16) {
                $departDt .= ':00';
            }
        }
        if ($arriveLocal !== null) {
            $arriveDt = str_replace('T', ' ', $arriveLocal);
            if (strlen($arriveDt) === 16) {
                $arriveDt .= ':00';
            }
        }

        if ($errors === [] && $operator !== null) {
            try {
                if (!$app['db']->columnExists('trip_segments', 'carrier_id')) {
                    throw new RuntimeException(
                        'Database is missing trip_segments.carrier_id. Run: php scripts/migrate.php'
                    );
                }

                $startDate = substr((string) $departDt, 0, 10);
                $endDate = $arriveDt !== null ? substr($arriveDt, 0, 10) : $startDate;
                if ($endDate < $startDate) {
                    $endDate = $startDate;
                }

                $numberOnly = preg_replace('/[^0-9A-Za-z]/', '', (string) $trainNumber) ?? '';
                $numberOnly = ltrim($numberOnly, '0') ?: $numberOnly;

                $trip = $tripRepo->create(new Trip(
                    id: null,
                    ownerId: $user->id,
                    destinationCity: $destination,
                    startDate: $startDate,
                    endDate: $endDate,
                    status: 'planned',
                    tripPurpose: $purpose,
                    notes: $notes,
                    isPrivate: $isPrivate,
                ), $user->id);

                $tripRepo->addSegment(new TripSegment(
                    id: null,
                    tripId: (int) $trip->id,
                    segmentType: 'train',
                    segmentSubtype: null,
                    carrierId: (int) $operator->id,
                    carrier: $operator->name,
                    flightNumber: $numberOnly,
                    confirmationCode: $confirmation,
                    origin: $origin,
                    destination: $destination,
                    departDt: $departDt,
                    arriveDt: $arriveDt,
                    hotelStayId: null,
                    status: 'scheduled',
                    sourceParseLogId: null,
                ), $user->id);

                if (!$isPrivate && $hideFrom !== []) {
                    $blockRepo->replaceBlocks(
                        $user->id,
                        VisibilityBlockRepository::TYPE_TRIP,
                        (int) $trip->id,
                        $hideFrom,
                        $user->id
                    );
                }

                header('Location: /dashboard/index.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$isPrivate = isset($_POST['is_private']) && $_POST['is_private'] === '1';
$blockedUserIds = array_map('intval', $_POST['hide_from'] ?? []);
$selectedCarrierId = (int) ($_POST['carrier_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Add a Train</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/carrier-picker.js'), ENT_QUOTES) ?>" defer></script>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Add a Train</h1>
    <p class="hint">Pick a rail operator, then enter the train number (e.g. <code>90</code> for Amtrak).</p>

    <?php if ($schemaWarning !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($schemaWarning, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form class="stack" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

        <label>Operator
            <select name="carrier_id" id="carrier_id" required data-api="/trains/api_operator.php" data-carrier-type="rail" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                <option value="">— Select operator —</option>
                <?php foreach ($operators as $c): ?>
                    <option value="<?= (int) $c->id ?>" <?= $selectedCarrierId === $c->id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c->label(), ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
                <option value="__new__">— Add New… —</option>
            </select>
        </label>
        <p class="hint">Use Add New in the list if an operator is missing.<?php if ($userRepo->isAdmin($user)): ?> Site catalog: <a href="/settings/site.php#rail-operators">Manage rail operators</a>.<?php endif; ?></p>

        <label>Train number<input type="text" name="train_number" required value="<?= htmlspecialchars((string) ($_POST['train_number'] ?? ''), ENT_QUOTES) ?>" placeholder="90" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Origin (station or city)<input type="text" name="origin" required value="<?= htmlspecialchars((string) ($_POST['origin'] ?? ''), ENT_QUOTES) ?>" placeholder="New York, NY" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Destination (station or city)<input type="text" name="destination" required value="<?= htmlspecialchars((string) ($_POST['destination'] ?? ''), ENT_QUOTES) ?>" placeholder="Washington, DC" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Departure<input type="datetime-local" name="depart_dt" required value="<?= htmlspecialchars((string) ($_POST['depart_dt'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Arrival (optional)<input type="datetime-local" name="arrive_dt" value="<?= htmlspecialchars((string) ($_POST['arrive_dt'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Reservation / confirmation #<input type="text" name="confirmation_code" value="<?= htmlspecialchars((string) ($_POST['confirmation_code'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Trip purpose<input type="text" name="trip_purpose" value="<?= htmlspecialchars((string) ($_POST['trip_purpose'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Notes<textarea name="notes" rows="3" <?= $schemaWarning !== null ? 'disabled' : '' ?>><?= htmlspecialchars((string) ($_POST['notes'] ?? ''), ENT_QUOTES) ?></textarea></label>

        <?php
        $legend = 'Privacy';
        require __DIR__ . '/../_privacy_fieldset.php';
        ?>

        <button type="submit" class="primary" <?= $schemaWarning !== null ? 'disabled' : '' ?>>Save train</button>
    </form>
</main>

<div id="carrier-modal" class="modal-backdrop" hidden>
    <div class="modal-panel" role="dialog" aria-labelledby="carrier-modal-title">
        <h2 id="carrier-modal-title">Add rail operator</h2>
        <p id="carrier-modal-error" class="alert alert-error" hidden></p>
        <form id="carrier-modal-form" class="stack">
            <label>Operator name<input type="text" name="name" required placeholder="Amtrak"></label>
            <label>IATA / code (optional)<input type="text" name="iata_code" maxlength="3" placeholder="2V" style="text-transform:uppercase"></label>
            <p class="hint">Optional. Amtrak is commonly <code>2V</code>.</p>
            <div class="modal-actions">
                <button type="submit" class="primary">Save operator</button>
                <button type="button" class="secondary" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
