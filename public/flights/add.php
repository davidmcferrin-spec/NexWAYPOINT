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
$carriers = [];

if (!$app['db']->tableExists('carriers')) {
    $schemaWarning = 'Database is missing the carriers table. On the server run: php scripts/migrate.php';
} else {
    try {
        $carriers = $carrierRepo->findByType(Carrier::TYPE_AIRLINE);
    } catch (Throwable $e) {
        $app['logger']->error('Failed loading carriers for flight form', [
            'error' => $e->getMessage(),
        ]);
        $schemaWarning = 'Could not load carriers. If you just pulled new code, run: php scripts/migrate.php';
    }
}

$otherUsers = array_values(array_filter(
    $userRepo->findAllActive(),
    static fn ($u) => $u->id !== $user->id
));

function nullableTrimFlight(?string $value): ?string
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
        $flightNumber = nullableTrimFlight($_POST['flight_number'] ?? null);
        $origin = nullableTrimFlight($_POST['origin'] ?? null);
        $destination = nullableTrimFlight($_POST['destination'] ?? null);
        $departLocal = nullableTrimFlight($_POST['depart_dt'] ?? null);
        $arriveLocal = nullableTrimFlight($_POST['arrive_dt'] ?? null);
        $confirmation = nullableTrimFlight($_POST['confirmation_code'] ?? null);
        $purpose = nullableTrimFlight($_POST['trip_purpose'] ?? null);
        $notes = nullableTrimFlight($_POST['notes'] ?? null);
        $isPrivate = isset($_POST['is_private']) && $_POST['is_private'] === '1';
        $hideFrom = array_map('intval', $_POST['hide_from'] ?? []);

        $carrier = $carrierId > 0 ? $carrierRepo->find($carrierId) : null;
        if ($carrier === null || $carrier->userId !== $user->id) {
            $errors[] = 'Select a carrier (or Add New).';
        } elseif ($carrier->iataCode === null || $carrier->iataCode === '') {
            $errors[] = 'Selected carrier is missing an IATA code. Edit it under Settings → Site catalogs.';
        }
        if ($flightNumber === null) {
            $errors[] = 'Flight number is required.';
        }
        if ($origin === null) {
            $errors[] = 'Origin airport/city is required.';
        }
        if ($destination === null) {
            $errors[] = 'Destination airport/city is required.';
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

        if ($errors === [] && $carrier !== null) {
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

                // Store digits-only flight number; IATA lives on the carrier.
                $numberOnly = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $flightNumber) ?? '');
                $iata = strtoupper((string) $carrier->iataCode);
                if (str_starts_with($numberOnly, $iata) && strlen($numberOnly) > strlen($iata)) {
                    $numberOnly = substr($numberOnly, strlen($iata));
                }

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
                    segmentType: 'flight',
                    segmentSubtype: null,
                    carrierId: (int) $carrier->id,
                    carrier: $carrier->name,
                    flightNumber: $numberOnly,
                    confirmationCode: $confirmation,
                    origin: strtoupper((string) $origin),
                    destination: strtoupper((string) $destination),
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
    <title>NexWAYPOINT &middot; Add a Flight</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/carrier-picker.js'), ENT_QUOTES) ?>" defer></script>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Add a Flight</h1>
    <p class="hint">Pick a carrier (IATA is stored on the carrier). Enter only the flight number, e.g. <code>1234</code> not <code>DL1234</code>.</p>

    <?php if ($schemaWarning !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($schemaWarning, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form class="stack" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

        <label>Carrier
            <select name="carrier_id" id="carrier_id" required data-api="/flights/api_carrier.php" data-carrier-type="airline" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                <option value="">— Select carrier —</option>
                <?php foreach ($carriers as $c): ?>
                    <option value="<?= (int) $c->id ?>" <?= $selectedCarrierId === $c->id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c->label(), ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
                <option value="__new__">— Add New… —</option>
            </select>
        </label>
        <p class="hint">Use Add New in the list if a carrier is missing.<?php if ($userRepo->isAdmin($user)): ?> Site catalog: <a href="/settings/site.php#airline-carriers">Manage carriers</a>.<?php endif; ?></p>

        <label>Flight number<input type="text" name="flight_number" required value="<?= htmlspecialchars((string) ($_POST['flight_number'] ?? ''), ENT_QUOTES) ?>" placeholder="1234" inputmode="numeric" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Origin (airport code or city)<input type="text" name="origin" required value="<?= htmlspecialchars((string) ($_POST['origin'] ?? ''), ENT_QUOTES) ?>" placeholder="ORD" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Destination (airport code or city)<input type="text" name="destination" required value="<?= htmlspecialchars((string) ($_POST['destination'] ?? ''), ENT_QUOTES) ?>" placeholder="ATL" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Departure<input type="datetime-local" name="depart_dt" required value="<?= htmlspecialchars((string) ($_POST['depart_dt'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Arrival (optional)<input type="datetime-local" name="arrive_dt" value="<?= htmlspecialchars((string) ($_POST['arrive_dt'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Confirmation code<input type="text" name="confirmation_code" value="<?= htmlspecialchars((string) ($_POST['confirmation_code'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Trip purpose<input type="text" name="trip_purpose" value="<?= htmlspecialchars((string) ($_POST['trip_purpose'] ?? ''), ENT_QUOTES) ?>" <?= $schemaWarning !== null ? 'disabled' : '' ?>></label>
        <label>Notes<textarea name="notes" rows="3" <?= $schemaWarning !== null ? 'disabled' : '' ?>><?= htmlspecialchars((string) ($_POST['notes'] ?? ''), ENT_QUOTES) ?></textarea></label>

        <?php
        $legend = 'Privacy';
        require __DIR__ . '/../_privacy_fieldset.php';
        ?>

        <button type="submit" class="primary" <?= $schemaWarning !== null ? 'disabled' : '' ?>>Save flight</button>
    </form>
</main>

<div id="carrier-modal" class="modal-backdrop" hidden>
    <div class="modal-panel" role="dialog" aria-labelledby="carrier-modal-title">
        <h2 id="carrier-modal-title">Add carrier</h2>
        <p id="carrier-modal-error" class="alert alert-error" hidden></p>
        <form id="carrier-modal-form" class="stack">
            <label>Airline name<input type="text" name="name" required placeholder="Delta Air Lines"></label>
            <label>IATA code<input type="text" name="iata_code" required maxlength="3" placeholder="DL" style="text-transform:uppercase"></label>
            <p class="hint">IATA is used with the flight number for FlightAware lookups.</p>
            <div class="modal-actions">
                <button type="submit" class="primary">Save carrier</button>
                <button type="button" class="secondary" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
