<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Core\Env;
use NexWaypoint\Hotels\HotelProperty;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$stayRepo = new HotelStayRepository($app['db'], $app['logger'], $propertyRepo);
$userRepo = new UserRepository($app['db'], $app['logger']);
$blockRepo = new VisibilityBlockRepository($app['db']);

$existingProperties = $propertyRepo->findForUser($user->id);
$otherUsers = array_values(array_filter(
    $userRepo->findAllActive(),
    static fn ($u) => $u->id !== $user->id
));

$errors = [];
$blacklistWarning = null;

function nullableTrim(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

$checkbox = static fn (string $key): bool => isset($_POST[$key]) && $_POST[$key] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $selectedPropertyId = (int) ($_POST['hotel_property_id'] ?? 0);
        $property = null;

        try {
            if ($selectedPropertyId > 0) {
                $property = $propertyRepo->find($selectedPropertyId);
                if ($property === null || $property->userId !== $user->id) {
                    throw new InvalidArgumentException('Selected property was not found.');
                }
                // Allow updating amenities on an existing property when logging a new stay.
                $property = $propertyRepo->update(new HotelProperty(
                    id: $property->id,
                    userId: $user->id,
                    hotelName: $property->hotelName,
                    brand: nullableTrim($_POST['brand'] ?? null) ?? $property->brand,
                    addressLine1: nullableTrim($_POST['address_line1'] ?? null) ?? $property->addressLine1,
                    addressLine2: nullableTrim($_POST['address_line2'] ?? null) ?? $property->addressLine2,
                    city: nullableTrim($_POST['city'] ?? null) ?? $property->city,
                    stateRegion: nullableTrim($_POST['state_region'] ?? null) ?? $property->stateRegion,
                    postalCode: nullableTrim($_POST['postal_code'] ?? null) ?? $property->postalCode,
                    country: nullableTrim($_POST['country'] ?? null) ?? $property->country,
                    latitude: $property->latitude,
                    longitude: $property->longitude,
                    hasDesk: $checkbox('has_desk'),
                    deskNotes: nullableTrim($_POST['desk_notes'] ?? null),
                    hasPool: $checkbox('has_pool'),
                    hasHotTub: $checkbox('has_hot_tub'),
                    hasBreakfast: $checkbox('has_breakfast'),
                    breakfastNotes: nullableTrim($_POST['breakfast_notes'] ?? null),
                    hasGym: $checkbox('has_gym'),
                    hasFreeParking: $checkbox('has_free_parking'),
                    hasAirportShuttle: $checkbox('has_airport_shuttle'),
                    hasEvCharging: $checkbox('has_ev_charging'),
                    hasOnsiteRestaurant: $checkbox('has_onsite_restaurant'),
                    hasOffsiteGym: $checkbox('has_offsite_gym'),
                    walkToOffice: $checkbox('walk_to_office'),
                    walkToOfficeNotes: nullableTrim($_POST['walk_to_office_notes'] ?? null),
                    wifiQuality: $_POST['wifi_quality'] !== '' ? (int) $_POST['wifi_quality'] : null,
                    noiseLevel: $_POST['noise_level'] !== '' ? (int) $_POST['noise_level'] : null,
                    uniqueFeatures: nullableTrim($_POST['unique_features'] ?? null),
                    isBlacklisted: $checkbox('is_blacklisted'),
                    blacklistReason: nullableTrim($_POST['blacklist_reason'] ?? null),
                    overallRating: $property->overallRating,
                ), $user->id);
            } else {
                $hotelName = trim((string) ($_POST['hotel_name'] ?? ''));
                $city = nullableTrim($_POST['city'] ?? null);
                $match = $propertyRepo->findMatchingBlacklist($user->id, $hotelName, $city);
                if ($match !== null) {
                    $blacklistWarning = "You blacklisted \"{$match->hotelName}\": " . ($match->blacklistReason ?? 'no reason recorded.');
                }

                $property = $propertyRepo->create(new HotelProperty(
                    id: null,
                    userId: $user->id,
                    hotelName: $hotelName,
                    brand: nullableTrim($_POST['brand'] ?? null),
                    addressLine1: nullableTrim($_POST['address_line1'] ?? null),
                    addressLine2: nullableTrim($_POST['address_line2'] ?? null),
                    city: $city,
                    stateRegion: nullableTrim($_POST['state_region'] ?? null),
                    postalCode: nullableTrim($_POST['postal_code'] ?? null),
                    country: nullableTrim($_POST['country'] ?? null),
                    latitude: null,
                    longitude: null,
                    hasDesk: $checkbox('has_desk'),
                    deskNotes: nullableTrim($_POST['desk_notes'] ?? null),
                    hasPool: $checkbox('has_pool'),
                    hasHotTub: $checkbox('has_hot_tub'),
                    hasBreakfast: $checkbox('has_breakfast'),
                    breakfastNotes: nullableTrim($_POST['breakfast_notes'] ?? null),
                    hasGym: $checkbox('has_gym'),
                    hasFreeParking: $checkbox('has_free_parking'),
                    hasAirportShuttle: $checkbox('has_airport_shuttle'),
                    hasEvCharging: $checkbox('has_ev_charging'),
                    hasOnsiteRestaurant: $checkbox('has_onsite_restaurant'),
                    hasOffsiteGym: $checkbox('has_offsite_gym'),
                    walkToOffice: $checkbox('walk_to_office'),
                    walkToOfficeNotes: nullableTrim($_POST['walk_to_office_notes'] ?? null),
                    wifiQuality: $_POST['wifi_quality'] !== '' ? (int) $_POST['wifi_quality'] : null,
                    noiseLevel: $_POST['noise_level'] !== '' ? (int) $_POST['noise_level'] : null,
                    uniqueFeatures: nullableTrim($_POST['unique_features'] ?? null),
                    isBlacklisted: $checkbox('is_blacklisted'),
                    blacklistReason: nullableTrim($_POST['blacklist_reason'] ?? null),
                ), $user->id);
            }

            $bedType = nullableTrim($_POST['bed_type'] ?? null);
            $bathroomType = nullableTrim($_POST['bathroom_type'] ?? null);

            $stay = new HotelStay(
                id: null,
                userId: $user->id,
                hotelPropertyId: (int) $property->id,
                roomNumber: nullableTrim($_POST['room_number'] ?? null),
                bedType: $bedType,
                bathroomType: $bathroomType,
                stayStart: (string) ($_POST['stay_start'] ?? ''),
                stayEnd: (string) ($_POST['stay_end'] ?? ''),
                stayRating: $_POST['stay_rating'] !== '' ? (int) $_POST['stay_rating'] : null,
                lastStayPrice: $_POST['last_stay_price'] !== '' ? (float) $_POST['last_stay_price'] : null,
                currency: trim((string) ($_POST['currency'] ?? 'USD')) ?: 'USD',
                bookingSource: nullableTrim($_POST['booking_source'] ?? null),
                confirmationCode: nullableTrim($_POST['confirmation_code'] ?? null),
                wouldReturn: isset($_POST['would_return']) && $_POST['would_return'] !== '' ? $_POST['would_return'] === '1' : null,
                notes: nullableTrim($_POST['notes'] ?? null),
                isPrivate: $checkbox('is_private'),
            );

            $created = $stayRepo->create($stay, $user->id);

            if (!$created->isPrivate) {
                $hideFrom = array_map('intval', $_POST['hide_from'] ?? []);
                if ($hideFrom !== []) {
                    $blockRepo->replaceBlocks(
                        $user->id,
                        VisibilityBlockRepository::TYPE_HOTEL_STAY,
                        (int) $created->id,
                        $hideFrom,
                        $user->id
                    );
                }
            }

            if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                $maxBytes = Env::getInt('HOTEL_PHOTO_MAX_BYTES', 5_242_880);
                $uploadDir = Env::get('HOTEL_PHOTO_UPLOAD_DIR', dirname(__DIR__, 2) . '/storage/uploads/hotel_photos');

                if ($_FILES['photo']['size'] > $maxBytes) {
                    $errors[] = 'Photo exceeds the maximum allowed size.';
                } else {
                    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                    $ext = strtolower(pathinfo((string) $_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt, true)) {
                        $errors[] = 'Photo must be jpg, png, or webp.';
                    } else {
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0775, true);
                        }
                        $filename = sprintf('%d_%s.%s', $created->id, bin2hex(random_bytes(8)), $ext);
                        $destination = rtrim($uploadDir, '/') . '/' . $filename;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                            $stayRepo->addPhoto($created->id, $destination, nullableTrim($_POST['photo_caption'] ?? null), $user->id);
                        } else {
                            $app['logger']->error('Photo upload move failed', ['hotel_stay_id' => $created->id]);
                        }
                    }
                }
            }

            if ($errors === []) {
                header('Location: /hotels/view.php?id=' . $created->id);
                exit;
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$selectedPropertyId = (int) ($_POST['hotel_property_id'] ?? $_GET['property_id'] ?? 0);
$prefill = null;
if ($selectedPropertyId > 0) {
    $prefill = $propertyRepo->find($selectedPropertyId);
    if ($prefill === null || $prefill->userId !== $user->id) {
        $prefill = null;
        $selectedPropertyId = 0;
    }
}

$isPrivate = isset($_POST['is_private']) && $_POST['is_private'] === '1';
$blockedUserIds = array_map('intval', $_POST['hide_from'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexWAYPOINT &middot; Log a Hotel Stay</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div>
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/list.php">Hotels</a>
        <a href="/hotels/add.php">+ Log a stay</a>
        <a href="/flights/add.php">+ Add a flight</a>
        <a href="/logout.php">Sign out</a>
    </div>
</nav>
<main class="container">
    <h1>Log a Hotel Stay</h1>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($blacklistWarning !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($blacklistWarning, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <form class="stack" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

        <fieldset>
            <legend>Property</legend>
            <label>Previously stayed property
                <select name="hotel_property_id" id="hotel_property_id" onchange="window.location='?property_id='+encodeURIComponent(this.value)">
                    <option value="0">— New property —</option>
                    <?php foreach ($existingProperties as $prop): ?>
                        <option value="<?= (int) $prop->id ?>" <?= $selectedPropertyId === $prop->id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($prop->label(), ENT_QUOTES) ?>
                            <?php if ($prop->overallRating !== null): ?>
                                (<?= number_format($prop->overallRating, 1) ?>★)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="hint">Pick a prior property to reuse amenities, or choose New property.</p>

            <label>Hotel name<input type="text" name="hotel_name" <?= $prefill === null ? 'required' : 'readonly' ?>
                value="<?= htmlspecialchars($prefill->hotelName ?? (string) ($_POST['hotel_name'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Brand<input type="text" name="brand" value="<?= htmlspecialchars($prefill->brand ?? (string) ($_POST['brand'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Address line 1<input type="text" name="address_line1" value="<?= htmlspecialchars($prefill->addressLine1 ?? (string) ($_POST['address_line1'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Address line 2<input type="text" name="address_line2" value="<?= htmlspecialchars($prefill->addressLine2 ?? (string) ($_POST['address_line2'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>City<input type="text" name="city" value="<?= htmlspecialchars($prefill->city ?? (string) ($_POST['city'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>State / region<input type="text" name="state_region" value="<?= htmlspecialchars($prefill->stateRegion ?? (string) ($_POST['state_region'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Postal code<input type="text" name="postal_code" value="<?= htmlspecialchars($prefill->postalCode ?? (string) ($_POST['postal_code'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Country<input type="text" name="country" value="<?= htmlspecialchars($prefill->country ?? (string) ($_POST['country'] ?? ''), ENT_QUOTES) ?>"></label>
        </fieldset>

        <fieldset>
            <legend>Amenities (property)</legend>
            <div class="checkbox-grid">
                <?php
                $amenityChecks = [
                    'has_desk' => 'Desk suitable for working',
                    'has_pool' => 'Pool',
                    'has_hot_tub' => 'Hot tub',
                    'has_breakfast' => 'Breakfast included',
                    'has_gym' => 'On-site gym',
                    'has_offsite_gym' => 'Off-site gym',
                    'has_free_parking' => 'Free parking',
                    'has_airport_shuttle' => 'Airport shuttle',
                    'has_ev_charging' => 'EV charging',
                    'has_onsite_restaurant' => 'On-site restaurant',
                    'walk_to_office' => 'Walking distance to office/venue',
                ];
                foreach ($amenityChecks as $name => $label):
                    $checked = $prefill !== null
                        ? match ($name) {
                            'has_desk' => $prefill->hasDesk,
                            'has_pool' => $prefill->hasPool,
                            'has_hot_tub' => $prefill->hasHotTub,
                            'has_breakfast' => $prefill->hasBreakfast,
                            'has_gym' => $prefill->hasGym,
                            'has_offsite_gym' => $prefill->hasOffsiteGym,
                            'has_free_parking' => $prefill->hasFreeParking,
                            'has_airport_shuttle' => $prefill->hasAirportShuttle,
                            'has_ev_charging' => $prefill->hasEvCharging,
                            'has_onsite_restaurant' => $prefill->hasOnsiteRestaurant,
                            'walk_to_office' => $prefill->walkToOffice,
                            default => false,
                        }
                        : (isset($_POST[$name]) && $_POST[$name] === '1');
                    ?>
                    <label><input type="checkbox" name="<?= $name ?>" value="1" <?= $checked ? 'checked' : '' ?>> <?= htmlspecialchars($label, ENT_QUOTES) ?></label>
                <?php endforeach; ?>
            </div>
            <label>Which office / venue (if walking distance)<input type="text" name="walk_to_office_notes"
                value="<?= htmlspecialchars($prefill->walkToOfficeNotes ?? (string) ($_POST['walk_to_office_notes'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Desk notes<input type="text" name="desk_notes" value="<?= htmlspecialchars($prefill->deskNotes ?? (string) ($_POST['desk_notes'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Breakfast notes<input type="text" name="breakfast_notes" value="<?= htmlspecialchars($prefill->breakfastNotes ?? (string) ($_POST['breakfast_notes'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>WiFi quality (1-5)
                <select name="wifi_quality">
                    <option value="">—</option>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>" <?= (($prefill->wifiQuality ?? ($_POST['wifi_quality'] ?? '')) == $i) ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Noise level (1 = quiet, 5 = loud)
                <select name="noise_level">
                    <option value="">—</option>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>" <?= (($prefill->noiseLevel ?? ($_POST['noise_level'] ?? '')) == $i) ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Unique features<textarea name="unique_features" rows="2"><?= htmlspecialchars($prefill->uniqueFeatures ?? (string) ($_POST['unique_features'] ?? ''), ENT_QUOTES) ?></textarea></label>
            <label><input type="checkbox" name="is_blacklisted" value="1" <?= ($prefill?->isBlacklisted || (isset($_POST['is_blacklisted']) && $_POST['is_blacklisted'] === '1')) ? 'checked' : '' ?>> Blacklist this property</label>
            <label>Blacklist reason<input type="text" name="blacklist_reason" value="<?= htmlspecialchars($prefill->blacklistReason ?? (string) ($_POST['blacklist_reason'] ?? ''), ENT_QUOTES) ?>"></label>
        </fieldset>

        <fieldset>
            <legend>This stay</legend>
            <label>Check-in date<input type="date" name="stay_start" required value="<?= htmlspecialchars((string) ($_POST['stay_start'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Check-out date<input type="date" name="stay_end" required value="<?= htmlspecialchars((string) ($_POST['stay_end'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Room number<input type="text" name="room_number" value="<?= htmlspecialchars((string) ($_POST['room_number'] ?? ''), ENT_QUOTES) ?>"></label>
            <label>Bed type
                <select name="bed_type">
                    <option value="">—</option>
                    <option value="king" <?= (($_POST['bed_type'] ?? '') === 'king') ? 'selected' : '' ?>>King</option>
                    <option value="queen" <?= (($_POST['bed_type'] ?? '') === 'queen') ? 'selected' : '' ?>>Queen</option>
                    <option value="dual_queen" <?= (($_POST['bed_type'] ?? '') === 'dual_queen') ? 'selected' : '' ?>>Dual queen</option>
                </select>
            </label>
            <label>Bathroom
                <select name="bathroom_type">
                    <option value="">—</option>
                    <option value="tub" <?= (($_POST['bathroom_type'] ?? '') === 'tub') ? 'selected' : '' ?>>Tub</option>
                    <option value="walk_in_shower" <?= (($_POST['bathroom_type'] ?? '') === 'walk_in_shower') ? 'selected' : '' ?>>Walk-in shower</option>
                </select>
            </label>
            <label>Stay rating (1-5) — feeds overall property rating
                <select name="stay_rating">
                    <option value="">—</option>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>" <?= (($_POST['stay_rating'] ?? '') == (string) $i) ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Would you return?
                <select name="would_return">
                    <option value="">—</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </select>
            </label>
            <label>Notes<textarea name="notes" rows="3"><?= htmlspecialchars((string) ($_POST['notes'] ?? ''), ENT_QUOTES) ?></textarea></label>
            <label>Last stay price<input type="number" step="0.01" name="last_stay_price"></label>
            <label>Currency<input type="text" name="currency" value="USD" maxlength="3"></label>
            <label>Booking source<input type="text" name="booking_source"></label>
            <label>Confirmation code<input type="text" name="confirmation_code"></label>
        </fieldset>

        <?php
        $legend = 'Privacy';
        require __DIR__ . '/../_privacy_fieldset.php';
        ?>

        <label>Photo (optional)<input type="file" name="photo" accept="image/png,image/jpeg,image/webp"></label>
        <label>Photo caption<input type="text" name="photo_caption"></label>

        <button type="submit" class="primary">Save stay</button>
    </form>
</main>
</body>
</html>
