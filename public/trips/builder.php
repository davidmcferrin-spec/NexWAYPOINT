<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\Carrier;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$userRepo = new UserRepository($app['db'], $app['logger']);
$tripRepo = new TripRepository($app['db'], $app['logger']);
$carrierRepo = new CarrierRepository($app['db'], $app['logger']);
$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$stayRepo = new HotelStayRepository($app['db'], $app['logger'], $propertyRepo);
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
$airlines = [];
$railOperators = [];

if (!$app['db']->tableExists('carriers')) {
    $schemaWarning = 'Database is missing the carriers table. Run: php scripts/migrate.php';
} else {
    try {
        $airlines = $carrierRepo->findByType(Carrier::TYPE_AIRLINE);
        $railOperators = $carrierRepo->findByType(Carrier::TYPE_RAIL);
    } catch (Throwable $e) {
        $schemaWarning = 'Could not load carriers.';
        $app['logger']->error('Failed loading carriers for trip builder', ['error' => $e->getMessage()]);
    }
}

$otherUsers = array_values(array_filter(
    $userRepo->findAllActive(),
    static fn ($u) => $u->id !== $user->id
));

$allProperties = $propertyRepo->findAll();
$userStays = $stayRepo->findForUser($user->id, 'stay_start DESC');

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
        if (!in_array($segment->segmentType, ['flight', 'train'], true)) {
            continue;
        }
        $legs[] = [
            'segment_type' => $segment->segmentType,
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

/**
 * @param HotelStay[] $stays
 * @param HotelPropertyRepository $properties
 * @param list<int> $stayIds
 * @return list<array<string, mixed>>
 */
function nx_builder_hotel_rows(array $stays, HotelPropertyRepository $properties, array $stayIds): array
{
    $byId = [];
    foreach ($stays as $stay) {
        if ($stay->id !== null) {
            $byId[(int) $stay->id] = $stay;
        }
    }
    $rows = [];
    foreach ($stayIds as $id) {
        $stay = $byId[$id] ?? null;
        if ($stay === null) {
            continue;
        }
        $prop = $properties->find($stay->hotelPropertyId);
        $rows[] = [
            'stay_id' => (int) $stay->id,
            'label' => $prop !== null ? $prop->hotelName : 'Hotel',
            'city' => $prop !== null ? (string) $prop->city : '',
            'stay_start' => $stay->stayStart,
            'stay_end' => $stay->stayEnd,
        ];
    }
    return $rows;
}

$postedLegs = null;
$postedHotelStayIds = null;

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
            $mode = strtolower((string) ($row['segment_type'] ?? 'flight'));
            if (!in_array($mode, ['flight', 'train'], true)) {
                $mode = 'flight';
            }
            $carrierId = (int) ($row['carrier_id'] ?? 0);
            $flightNumber = nx_builder_trim($row['flight_number'] ?? null);
            $origin = nx_builder_trim($row['origin'] ?? null);
            $destination = nx_builder_trim($row['destination'] ?? null);
            $departLocal = nx_builder_trim($row['depart_dt'] ?? null);
            $arriveLocal = nx_builder_trim($row['arrive_dt'] ?? null);
            $confirmation = nx_builder_trim($row['confirmation_code'] ?? null);

            if ($carrierId <= 0 && $flightNumber === null && $origin === null && $destination === null
                && $departLocal === null && $arriveLocal === null && $confirmation === null) {
                continue;
            }

            $carrier = $carrierId > 0 ? $carrierRepo->find($carrierId) : null;
            if ($carrier === null) {
                $errors[] = 'Each leg needs a carrier / operator.';
                continue;
            }
            if ($mode === 'flight') {
                if ($carrier->isRail()) {
                    $errors[] = 'Flight legs need an airline carrier.';
                    continue;
                }
                if ($carrier->iataCode === null || $carrier->iataCode === '') {
                    $errors[] = 'Carrier ' . $carrier->name . ' is missing an IATA code.';
                    continue;
                }
            } elseif (!$carrier->isRail()) {
                $errors[] = 'Train legs need a rail operator.';
                continue;
            }
            if ($flightNumber === null) {
                $errors[] = ($mode === 'train' ? 'Train' : 'Flight') . ' number is required on every leg.';
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

            $number = $mode === 'flight'
                ? nx_builder_flight_number($flightNumber, $carrier)
                : strtoupper(preg_replace('/[^A-Z0-9]/', '', $flightNumber) ?? '');

            $legs[] = [
                'segment_type' => $mode,
                'carrier_id' => (int) $carrier->id,
                'carrier' => $carrier->name,
                'flight_number' => $number,
                'origin' => strtoupper($origin),
                'destination' => strtoupper($destination),
                'depart_dt' => nx_builder_datetime($departLocal),
                'arrive_dt' => nx_builder_datetime($arriveLocal),
                'confirmation_code' => $confirmation !== null ? strtoupper($confirmation) : null,
                'status' => 'scheduled',
            ];
        }

        $postedLegs = $legs;

        // Hotel attachments: existing stay ids + newly created stays.
        $hotelStayIds = [];
        $rawHotels = $_POST['hotels'] ?? [];
        if (is_array($rawHotels)) {
            foreach ($rawHotels as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sid = (int) ($row['stay_id'] ?? 0);
                if ($sid > 0) {
                    $hotelStayIds[] = $sid;
                }
            }
        }

        $rawNewHotels = $_POST['hotels_new'] ?? [];
        if (!is_array($rawNewHotels)) {
            $rawNewHotels = [];
        }

        if ($legs === []) {
            $errors[] = 'Add at least one flight or train leg.';
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

                $tripRepo->replaceTripLegs((int) $trip->id, $legs, $user->id);

                foreach ($rawNewHotels as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $propertyId = (int) ($row['property_id'] ?? 0);
                    $stayStart = nx_builder_trim($row['stay_start'] ?? null);
                    $stayEnd = nx_builder_trim($row['stay_end'] ?? null);
                    if ($propertyId <= 0 && $stayStart === null && $stayEnd === null) {
                        continue;
                    }
                    if ($propertyId <= 0 || $stayStart === null || $stayEnd === null) {
                        throw new InvalidArgumentException('New hotels need a property and check-in/out dates.');
                    }
                    $property = $propertyRepo->find($propertyId);
                    if ($property === null) {
                        throw new InvalidArgumentException('Selected hotel property was not found.');
                    }
                    $createdStay = $stayRepo->create(new HotelStay(
                        id: null,
                        userId: $user->id,
                        hotelPropertyId: (int) $property->id,
                        roomNumber: null,
                        bedType: null,
                        bathroomType: null,
                        stayStart: $stayStart,
                        stayEnd: $stayEnd,
                        stayRating: null,
                        lastStayPrice: null,
                        currency: 'USD',
                        bookingSource: null,
                        confirmationCode: null,
                        wouldReturn: null,
                        notes: 'Linked from trip itinerary',
                        isPrivate: false,
                    ), $user->id);
                    if ($createdStay->id !== null) {
                        $hotelStayIds[] = (int) $createdStay->id;
                    }
                }

                $hotelStayIds = array_values(array_unique(array_map('intval', $hotelStayIds)));
                $postedHotelStayIds = $hotelStayIds;
                $tripRepo->replaceTripHotels(
                    (int) $trip->id,
                    $hotelStayIds,
                    $propertyRepo,
                    $stayRepo,
                    $user->id
                );

                header('Location: /trips/view.php?id=' . (int) $trip->id);
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$emptyLeg = [
    'segment_type' => 'flight',
    'carrier_id' => null,
    'flight_number' => '',
    'origin' => '',
    'destination' => '',
    'depart_dt' => '',
    'arrive_dt' => '',
    'confirmation_code' => '',
];

if ($postedLegs !== null) {
    $initialLegs = [];
    foreach ($postedLegs as $leg) {
        $initialLegs[] = [
            'segment_type' => $leg['segment_type'] ?? 'flight',
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
        $initialLegs = [$emptyLeg];
    }
} else {
    $initialLegs = [$emptyLeg];
}

if ($postedHotelStayIds !== null) {
    $attachedStayIds = $postedHotelStayIds;
} elseif ($isEdit) {
    $attachedStayIds = $tripRepo->hotelStayIdsForTrip((int) $trip->id);
} else {
    $attachedStayIds = [];
}

// Refresh stays after possible create failures leave POST state.
$userStays = $stayRepo->findForUser($user->id, 'stay_start DESC');
$attachedHotels = nx_builder_hotel_rows($userStays, $propertyRepo, $attachedStayIds);

$attachableStays = [];
$attachedSet = array_fill_keys($attachedStayIds, true);
$todayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');
foreach ($userStays as $stay) {
    if ($stay->id === null || isset($attachedSet[(int) $stay->id])) {
        continue;
    }
    // Past stays clutter the picker; only current/upcoming (checkout today or later).
    if ($stay->stayEnd < $todayYmd) {
        continue;
    }
    $prop = $propertyRepo->find($stay->hotelPropertyId);
    $attachableStays[] = [
        'stay_id' => (int) $stay->id,
        'label' => ($prop !== null ? $prop->hotelName : 'Hotel')
            . ' · ' . $stay->stayStart . ' → ' . $stay->stayEnd
            . ($prop !== null && $prop->city ? ' · ' . $prop->city : ''),
    ];
}

$destinationValue = (string) ($_POST['destination_city'] ?? ($isEdit ? $trip->destinationCity : ''));
$purposeValue = (string) ($_POST['trip_purpose'] ?? ($isEdit ? (string) $trip->tripPurpose : ''));
$notesValue = (string) ($_POST['notes'] ?? ($isEdit ? (string) $trip->notes : ''));
$isPrivate = isset($_POST['is_private'])
    ? ($_POST['is_private'] === '1')
    : ($isEdit ? $trip->isPrivate : false);
$blockedUserIds = array_map('intval', $_POST['hide_from'] ?? []);
$statusValue = (string) ($_POST['status'] ?? ($isEdit ? $trip->status : 'planned'));

$carrierJson = static function (array $list): array {
    return array_map(static function (Carrier $c): array {
        return ['id' => (int) $c->id, 'label' => $c->label()];
    }, $list);
};

$propertyOptions = array_map(static function ($p): array {
    return [
        'id' => (int) $p->id,
        'label' => $p->hotelName . ($p->city ? ' · ' . $p->city : ''),
    ];
}, $allProperties);

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
<main class="container container-wide trip-builder">
    <div class="trip-builder-panel">
        <header class="trip-builder-header">
            <div>
                <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>
                <p class="hint">Mix flights and trains. Gaps ≤3h are connections; longer gaps show as stays. Attach one or more hotels (e.g. DC then NY) for at-hotel status and map pins.</p>
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

            <h2 class="trip-builder-section-title">Transit legs</h2>
            <div class="table-scroll">
                <table class="trip-legs-table" id="trip-legs-table">
                    <thead>
                        <tr>
                            <th>Mode</th>
                            <th>Carrier</th>
                            <th class="leg-num-heading">Flight #</th>
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
            </div>

            <h2 class="trip-builder-section-title">Hotels on this trip</h2>
            <p class="hint">Multiple stays are fine on one trip — attach or add each city separately.</p>
            <div id="trip-hotels-attached" class="trip-hotels-list"></div>
            <div class="trip-hotels-controls">
                <label class="trip-hotel-attach">Attach existing stay
                    <select id="trip-hotel-attach-select" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                        <option value="">— Select upcoming stay —</option>
                        <?php foreach ($attachableStays as $opt): ?>
                            <option value="<?= (int) $opt['stay_id'] ?>"><?= htmlspecialchars($opt['label'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="button" class="secondary" id="trip-hotel-attach-btn" <?= $schemaWarning !== null ? 'disabled' : '' ?>>Attach</button>
            </div>
            <div class="trip-hotels-new" id="trip-hotels-new">
                <p class="hint">Or add a new stay (creates a hotel stay and links it):</p>
                <div class="trip-hotels-new-row">
                    <label>Property
                        <select id="trip-hotel-new-property" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                            <option value="">— Select property —</option>
                            <?php foreach ($propertyOptions as $opt): ?>
                                <option value="<?= (int) $opt['id'] ?>"><?= htmlspecialchars($opt['label'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Check-in
                        <input type="date" id="trip-hotel-new-start" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                    </label>
                    <label>Check-out
                        <input type="date" id="trip-hotel-new-end" <?= $schemaWarning !== null ? 'disabled' : '' ?>>
                    </label>
                    <button type="button" class="secondary" id="trip-hotel-new-btn" <?= $schemaWarning !== null ? 'disabled' : '' ?>>Add hotel</button>
                </div>
            </div>
            <div id="trip-hotels-hidden"></div>

            <div class="trip-builder-actions">
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
            <select name="legs[__IDX__][segment_type]" class="leg-mode" required>
                <option value="flight">Flight</option>
                <option value="train">Train</option>
            </select>
        </td>
        <td>
            <select name="legs[__IDX__][carrier_id]" class="leg-carrier" required>
                <option value="">—</option>
                <option value="__new__">— Add New… —</option>
            </select>
        </td>
        <td><input type="text" name="legs[__IDX__][flight_number]" class="leg-flight" required placeholder="1234"></td>
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
    'airlines' => $carrierJson($airlines),
    'rail' => $carrierJson($railOperators),
    'hotels' => $attachedHotels,
    'attachable' => $attachableStays,
], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<div id="carrier-modal" class="modal-backdrop" hidden>
    <div class="modal-panel" role="dialog" aria-labelledby="carrier-modal-title">
        <h2 id="carrier-modal-title">Add carrier</h2>
        <p id="carrier-modal-error" class="alert alert-error" hidden></p>
        <form id="carrier-modal-form" class="stack">
            <label id="carrier-modal-name-label">Airline name<input type="text" name="name" required placeholder="Delta Air Lines"></label>
            <label id="carrier-modal-iata-wrap">IATA code<input type="text" name="iata_code" maxlength="3" placeholder="DL" style="text-transform:uppercase"></label>
            <p class="hint" id="carrier-modal-hint">IATA is used with the flight number for FlightAware lookups.</p>
            <div class="modal-actions">
                <button type="submit" class="primary">Save</button>
                <button type="button" class="secondary" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
