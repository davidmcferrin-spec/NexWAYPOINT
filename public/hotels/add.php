<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Core\Env;
use NexWaypoint\Hotels\HotelBrandRepository;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Hotels\OfficeVenueRepository;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$stayRepo = new HotelStayRepository($app['db'], $app['logger'], $propertyRepo);
$userRepo = new UserRepository($app['db'], $app['logger']);
$blockRepo = new VisibilityBlockRepository($app['db']);
$hotelBrandNames = (new HotelBrandRepository($app['db'], $app['logger']))->namesForSelect();
$walkToOfficeVenues = array_values(array_unique(array_merge(
    (new OfficeVenueRepository($app['db'], $app['logger']))->namesForSelect(),
    $propertyRepo->walkToOfficeVenues(),
)));
natcasesort($walkToOfficeVenues);
$walkToOfficeVenues = array_values($walkToOfficeVenues);

$existingProperties = $propertyRepo->findAll();
$otherUsers = array_values(array_filter(
    $userRepo->findAllActive(),
    static fn ($u) => $u->id !== $user->id
));

$errors = [];

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

        try {
            if ($selectedPropertyId < 1) {
                throw new InvalidArgumentException('Select a hotel property (or Add New from the property list).');
            }

            $property = $propertyRepo->find($selectedPropertyId);
            if ($property === null) {
                throw new InvalidArgumentException('Selected property was not found.');
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
if ($selectedPropertyId > 0) {
    $check = $propertyRepo->find($selectedPropertyId);
    if ($check === null) {
        $selectedPropertyId = 0;
    }
}

$propertiesJson = array_map(static function ($p) {
    return [
        'id' => $p->id,
        'hotel_name' => $p->hotelName,
        'city' => $p->city,
        'state_region' => $p->stateRegion,
        'location_key' => $p->locationKey(),
        'location_label' => $p->locationLabel(),
        'label' => $p->label(),
        'overall_rating' => $p->overallRating,
        'phone' => $p->phone,
    ];
}, $existingProperties);

$isPrivate = isset($_POST['is_private']) && $_POST['is_private'] === '1';
$blockedUserIds = array_map('intval', $_POST['hide_from'] ?? []);
$property = null; // for modal include
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Log a Hotel Stay</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/hotel-picker.js'), ENT_QUOTES) ?>" defer></script>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/address-search.js'), ENT_QUOTES) ?>" defer></script>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Log a Hotel Stay</h1>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form class="stack" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

        <fieldset id="hotel-picker"
            data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>"
            data-selected-id="<?= (int) $selectedPropertyId ?>"
            data-properties="<?= htmlspecialchars(json_encode($propertiesJson, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
            <legend>Property</legend>
            <p class="hint">Choose a City, State, then the hotel. Use Add New to create a property.</p>
            <label>City, State
                <select id="location_key" name="location_key">
                    <option value="">— Select location —</option>
                </select>
            </label>
            <label>Hotel property
                <select name="hotel_property_id" id="hotel_property_id" required>
                    <option value="">— Select property —</option>
                    <option value="__new__">— Add New… —</option>
                </select>
            </label>
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
            <label>Stay rating (0–5) — feeds public overall property rating
                <select name="stay_rating">
                    <option value="">—</option>
                    <?php for ($i = 0; $i <= 5; $i++): ?>
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

<div id="property-modal" class="modal-backdrop" hidden>
    <div class="modal-panel" role="dialog" aria-labelledby="property-modal-title">
        <h2 id="property-modal-title">Add hotel property</h2>
        <p id="property-modal-error" class="alert alert-error" hidden></p>
        <form id="property-modal-form" class="stack">
            <?php require __DIR__ . '/_property_form_fields.php'; ?>
            <div class="modal-actions">
                <button type="submit" class="primary">Save property</button>
                <button type="button" class="secondary" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
