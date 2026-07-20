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

$tripId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$trip = $tripId > 0 ? $tripRepo->find($tripId) : null;
$isEdit = $trip !== null;

if ($isEdit && $trip->ownerId !== $user->id) {
    http_response_code(404);
    echo 'Trip not found.';
    exit;
}

if ($isEdit && !in_array($trip->status, ['planned', 'active'], true)) {
    http_response_code(400);
    echo 'Only planned or active trips can be edited.';
    exit;
}

$errors = [];
$schemaWarning = null;
$carriers = [];

if (!$app['db']->tableExists('carriers')) {
    $schemaWarning = 'Database is missing the carriers table. Run: php scripts/migrate.php';
} else {
    try {
        $carriers = $carrierRepo->findByType(Carrier::TYPE_AIRLINE);
    } catch (Throwable $e) {
        $schemaWarning = 'Could not load carriers.';
        $app['logger']->error('Failed loading carriers for trip builder', ['error' => $e->getMessage()]);
    }
}

$otherUsers = array_values(array_filter(
    $userRepo->findAllActive(),
    static fn ($u) => $u->id !== $user->id
));

function nx_builder_trim(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

function nx_builder_datetime(?string $value): ?string
{
    $value = nx_builder_trim($value);
    if ($value === null) {
        return null;
    }
    $normalized = str_replace('T', ' ', $value);
    if (strlen($normalized) === 16) {
        $normalized .= ':00';
    }
    return $normalized;
}

function nx_builder_to_local(?string $sqlDt): string
{
    if ($sqlDt === null || $sqlDt === '') {
        return '';
    }
    return str_replace(' ', 'T', substr($sqlDt, 0, 16));
}

function nx_builder_flight_number(string $raw, Carrier $carrier): string
{
    $numberOnly = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
    $iata = strtoupper((string) $carrier->iataCode);
    if ($iata !== '' && str_starts_with($numberOnly, $iata) && strlen($numberOnly) > strlen($iata)) {
        $numberOnly = substr($numberOnly, strlen($iata));
    }
    return $numberOnly;
}

/**
 * @return list<array<string, mixed>>
 */
function nx_builder_existing_legs(TripRepository $tripRepo, Trip $trip): array
{
    $legs = [];
    foreach ($tripRepo->segmentsForTrip((int) $trip->id) as $segment) {
        if ($segment->segmentType !== 'flight') {
            continue;
        }
        $legs[] = [
            'carrier_id' => $segment->carrierId,
            'flight_number' => $segment->flightNumber,
            'origin' => $segment->origin,
            'destination' => $segment->destination,
            'depart_dt' => nx_builder_to_local($segment->departDt),
            'arrive_dt' => nx_builder_to_local($segment->arriveDt),
            'confirmation_code' => $segment->confirmationCode,
        ];
    }
    return $legs;
}

$postedLegs = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($schemaWarning !== null) {
        $errors[] = $schemaWarning;
    } elseif (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit.';
    } else {
        $destinationCity = nx_builder_trim($_POST['destination_city'] ?? null);
        $purpose = nx_builder_trim($_POST['trip_purpose'] ?? null);
        $notes = nx_builder_trim($_POST['notes'] ?? null);
        $isPrivate = isset($_POST['is_private']) && $_POST['is_private'] === '1';
        $hideFrom = array_map('intval', $_POST['hide_from'] ?? []);
        $status = $isEdit ? (string) ($_POST['status'] ?? $trip->status) : 'planned';
        if (!in_array($status, ['planned', 'active', 'completed', 'cancelled'], true)) {
            $status = $isEdit ? $trip->status : 'planned';
        }

        $rawLegs = $_POST['legs'] ?? [];
        if (!is_array($rawLegs)) {
            $rawLegs = [];
        }

        $legs = [];
        foreach ($rawLegs as $row) {
            if (!is_array($row)) {
                continue;
            }
            $carrierId = (int) ($row['carrier_id'] ?? 0);
            $flightNumber = nx_builder_trim($row['flight_number'] ?? null);
            $origin = nx_builder_trim($row['origin'] ?? null);
            $destination = nx_builder_trim($row['destination'] ?? null);
            $departLocal = nx_builder_trim($row['depart_dt'] ?? null);
            $arriveLocal = nx_builder_trim($row['arrive_dt'] ?? null);
            $confirmation = nx_builder_trim($row['confirmation_code'] ?? null);

            // Skip completely empty rows.
            if ($carrierId <= 0 && $flightNumber === null && $origin === null && $destination === null
                && $departLocal === null && $arriveLocal === null && $confirmation === null) {
                continue;
            }

            $carrier = $carrierId > 0 ? $carrierRepo->find($carrierId) : null;
            if ($carrier === null || $carrier->isRail()) {
                $errors[] = 'Each leg needs an airline carrier.';
                continue;
            }
            if ($carrier->iataCode === null || $carrier->iataCode === '') {
                $errors[] = 'Carrier ' . $carrier->name . ' is missing an IATA code.';
                continue;
            }
            if ($flightNumber === null) {
                $errors[] = 'Flight number is required on every leg.';
                continue;
            }
            if ($origin === null || $destination === null) {
                $errors[] = 'Origin and destination are required on every leg.';
                continue;
            }
            if ($departLocal === null) {
                $errors[] = 'Departure time is required on every leg.';
                continue;
            }

            $legs[] = [
                'segment_type' => 'flight',
                'carrier_id' => (int) $carrier->id,
                'carrier' => $carrier->name,
                'flight_number' => nx_builder_flight_number($flightNumber, $carrier),
                'origin' => strtoupper($origin),
                'destination' => strtoupper($destination),
                'depart_dt' => nx_builder_datetime($departLocal),
                'arrive_dt' => nx_builder_datetime($arriveLocal),
                'confirmation_code' => $confirmation !== null ? strtoupper($confirmation) : null,
                'status' => 'scheduled',
            ];
        }

        $postedLegs = $legs;

        if ($legs === []) {
            $errors[] = 'Add at least one flight leg.';
        }

        if ($errors === []) {
            try {
                if (!$app['db']->columnExists('trip_segments', 'carrier_id')) {
                    throw new RuntimeException('Database is missing trip_segments.carrier_id. Run: php scripts/migrate.php');
                }

                $inferred = $tripRepo->destinationCityFromLegs($legs);
                if ($destinationCity === null || $destinationCity === '') {
                    $destinationCity = $inferred ?? ($legs[0]['destination'] ?? 'Travel');
                }

                $startDate = substr((string) $legs[0]['depart_dt'], 0, 10);
                $endDate = $startDate;
                foreach ($legs as $leg) {
                    foreach (['arrive_dt', 'depart_dt'] as $key) {
                        if (!empty($leg[$key])) {
                            $d = substr((string) $leg[$key], 0, 10);
                            if ($d > $endDate) {
                                $endDate = $d;
                            }
                            if ($d < $startDate) {
                                $startDate = $d;
                            }
                        }
                    }
                }

                if ($isEdit) {
                    $trip = $tripRepo->update(new Trip(
                        id: $trip->id,
                        ownerId: $trip->ownerId,
                        destinationCity: $destinationCity,
                        startDate: $startDate,
                        endDate: $endDate,
                        status: $status,
                        tripPurpose: $purpose,
                        notes: $notes,
                        isPrivate: $isPrivate,
                    ), $user->id);
                    $tripRepo->replaceTripLegs((int) $trip->id, $legs, $user->id);
                } else {
                    $trip = $tripRepo->create(new Trip(
                        id: null,
                        ownerId: $user->id,
                        destinationCity: $destinationCity,
                        startDate: $startDate,
                        endDate: $endDate,
                        status: 'planned',
                        tripPurpose: $purpose,
                        notes: $notes,
                        isPrivate: $isPrivate,
                    ), $user->id);
                    $tripRepo->replaceTripLegs((int) $trip->id, $legs, $user->id);

                    if (!$isPrivate && $hideFrom !== []) {
                        $blockRepo->replaceBlocks(
                            $user->id,
                            VisibilityBlockRepository::TYPE_TRIP,
                            (int) $trip->id,
                            $hideFrom,
                            $user->id
                        );
                    }
                }

                header('Location: /trips/view.php?id=' . (int) $trip->id);
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

if ($postedLegs !== null) {
    $initialLegs = [];
    foreach ($postedLegs as $leg) {
        $initialLegs[] = [
            'carrier_id' => $leg['carrier_id'] ?? null,
            'flight_number' => $leg['flight_number'] ?? '',
            'origin' => $leg['origin'] ?? '',
            'destination' => $leg['destination'] ?? '',
            'depart_dt' => nx_builder_to_local($leg['depart_dt'] ?? null),
            'arrive_dt' => nx_builder_to_local($leg['arrive_dt'] ?? null),
            'confirmation_code' => $leg['confirmation_code'] ?? '',
        ];
    }
} elseif ($isEdit) {
    $initialLegs = nx_builder_existing_legs($tripRepo, $trip);
    if ($initialLegs === []) {
        $initialLegs = [[
            'carrier_id' => null,
            'flight_number' => '',
            'origin' => '',
            'destination' => '',
            'depart_dt' => '',
            'arrive_dt' => '',
            'confirmation_code' => '',
        ]];
    }
} else {
    $initialLegs = [[
        'carrier_id' => null,
        'flight_number' => '',
        'origin' => '',
        'destination' => '',
        'depart_dt' => '',
        'arrive_dt' => '',
        'confirmation_code' => '',
    ]];
}

$destinationValue = (string) ($_POST['destination_city'] ?? ($isEdit ? $trip->destinationCity : ''));
$purposeValue = (string) ($_POST['trip_purpose'] ?? ($isEdit ? (string) $trip->tripPurpose : ''));
$notesValue = (string) ($_POST['notes'] ?? ($isEdit ? (string) $trip->notes : ''));
$isPrivate = isset($_POST['is_private'])
    ? ($_POST['is_private'] === '1')
    : ($isEdit ? $trip->isPrivate : false);
$blockedUserIds = array_map('intval', $_POST['hide_from'] ?? []);
$statusValue = (string) ($_POST['status'] ?? ($isEdit ? $trip->status : 'planned'));

$carrierOptionsJson = array_map(static function (Carrier $c): array {
    return ['id' => (int) $c->id, 'label' => $c->label()];
}, $carriers);

$pageTitle = $isEdit ? 'Edit trip itinerary' : 'Build trip itinerary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; <?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/trip-builder.js'), ENT_QUOTES) ?>" defer></script>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container trip-builder">
    <div class="trip-builder-panel">
        <header class="trip-builder-header">
            <div>
                <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>
                <p class="hint">Enter legs as local wall-clock times. Gaps ≤3h are connections; longer gaps show as stays.</p>
            </div>
            <a class="secondary" href="<?= $isEdit ? '/trips/view.php?id=' . (int) $trip->id : '/trips/list.php' ?>">Cancel</a>
        </header>

        <?php if ($schemaWarning !== null): ?>
            <p class="alert alert-error"><?= htmlspecialchars($schemaWarning, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endforeach; ?>

        <form method="post" id="trip-builder-form" class="stack" data-layover-hours="3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

            <div class="trip-builder-meta">
                <label>Destination city
                    <input type="text" name="destination_city" value="<?= htmlspecialchars($destinationValue, ENT_QUOTES) ?>"
                           placeholder="Auto from legs if blank" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                </label>
                <label>Purpose
                    <input type="text" name="trip_purpose" value="<?= htmlspecialchars($purposeValue, ENT_QUOTES) ?>"
                           <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                </label>
                <?php if ($isEdit): ?>
                    <label>Status
                        <select name="status" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                            <?php foreach (['planned', 'active', 'completed', 'cancelled'] as $st): ?>
                                <option value="<?= $st ?>" <?= $statusValue === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="checkbox-inline">
                        <input type="checkbox" name="is_private" value="1" <?= $isPrivate ? 'checked' : '' ?>
                               <?= $schemaWarning !== null ? 'disabled' : '' ?>> Private trip
                    </label>
                <?php endif; ?>
            </div>

            <div class="table-scroll">
                <table class="trip-legs-table" id="trip-legs-table">
                    <thead>
                        <tr>
                            <th>Carrier</th>
                            <th>Flight #</th>
                            <th>Origin</th>
                            <th>Dest</th>
                            <th>Depart (local)</th>
                            <th>Arrive (local)</th>
                            <th>Conf #</th>
                            <th>Gap</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="trip-legs-body"></tbody>
                </table>
            </div>

            <div class="trip-builder-actions">
                <button type="button" class="secondary" id="trip-leg-add" <?= $schemaWarning !== null ? 'disabled' : '' ?>>Add leg</button>
                <button type="submit" class="primary" <?= $schemaWarning !== null ? 'disabled' : '' ?>>Save itinerary</button>
            </div>

            <label>Notes
                <textarea name="notes" rows="2" <?= $schemaWarning !== null ? 'disabled' : '' ?>><?= htmlspecialchars($notesValue, ENT_QUOTES) ?></textarea>
            </label>

            <?php if (!$isEdit): ?>
                <?php
                $legend = 'Privacy';
                require dirname(__DIR__) . '/_privacy_fieldset.php';
                ?>
            <?php endif; ?>
        </form>
    </div>
</main>

<template id="trip-leg-row-template">
    <tr class="trip-leg-row">
        <td>
            <select name="legs[__IDX__][carrier_id]" class="leg-carrier" required>
                <option value="">—</option>
                <?php foreach ($carriers as $c): ?>
                    <option value="<?= (int) $c->id ?>"><?= htmlspecialchars($c->label(), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
                <option value="__new__">— Add New… —</option>
            </select>
        </td>
        <td><input type="text" name="legs[__IDX__][flight_number]" class="leg-flight" required placeholder="1234" inputmode="numeric"></td>
        <td><input type="text" name="legs[__IDX__][origin]" class="leg-origin" required placeholder="HSV" maxlength="32"></td>
        <td><input type="text" name="legs[__IDX__][destination]" class="leg-dest" required placeholder="DEN" maxlength="32"></td>
        <td><input type="datetime-local" name="legs[__IDX__][depart_dt]" class="leg-depart" required></td>
        <td><input type="datetime-local" name="legs[__IDX__][arrive_dt]" class="leg-arrive"></td>
        <td><input type="text" name="legs[__IDX__][confirmation_code]" class="leg-conf" placeholder="ABC123" maxlength="12"></td>
        <td class="leg-gap-hint" aria-live="polite"></td>
        <td class="leg-row-actions">
            <button type="button" class="linkish leg-move-up" title="Move up">↑</button>
            <button type="button" class="linkish leg-move-down" title="Move down">↓</button>
            <button type="button" class="linkish leg-remove" title="Remove">✕</button>
        </td>
    </tr>
</template>

<script type="application/json" id="trip-builder-initial"><?= json_encode([
    'legs' => $initialLegs,
    'carriers' => $carrierOptionsJson,
], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

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
