<?php

declare(strict_types=1);

use NexWaypont\Core\Csrf;
use NexWaypont\Core\Env;
use NexWaypont\Hotels\HotelStay;
use NexWaypont\Hotels\HotelStayRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$repo = new HotelStayRepository($app['db'], $app['logger']);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $checkbox = static fn (string $key): bool => isset($_POST[$key]) && $_POST[$key] === '1';

        $stay = new HotelStay(
            id: null,
            userId: $user->id,
            hotelName: trim((string) ($_POST['hotel_name'] ?? '')),
            brand: nullableTrim($_POST['brand'] ?? null),
            addressLine1: nullableTrim($_POST['address_line1'] ?? null),
            addressLine2: nullableTrim($_POST['address_line2'] ?? null),
            city: nullableTrim($_POST['city'] ?? null),
            stateRegion: nullableTrim($_POST['state_region'] ?? null),
            postalCode: nullableTrim($_POST['postal_code'] ?? null),
            country: nullableTrim($_POST['country'] ?? null),
            latitude: null,
            longitude: null,
            roomNumber: nullableTrim($_POST['room_number'] ?? null),
            stayStart: (string) ($_POST['stay_start'] ?? ''),
            stayEnd: (string) ($_POST['stay_end'] ?? ''),
            rating: $_POST['rating'] !== '' ? (int) $_POST['rating'] : null,
            hasDesk: $checkbox('has_desk'),
            deskNotes: nullableTrim($_POST['desk_notes'] ?? null),
            hasPool: $checkbox('has_pool'),
            hasHotTub: $checkbox('has_hot_tub'),
            hasBreakfast: $checkbox('has_breakfast'),
            breakfastNotes: nullableTrim($_POST['breakfast_notes'] ?? null),
            hasGym: $checkbox('has_gym'),
            hasFreeParking: $checkbox('has_free_parking'),
            hasAirportShuttle: $checkbox('has_airport_shuttle'),
            wifiQuality: $_POST['wifi_quality'] !== '' ? (int) $_POST['wifi_quality'] : null,
            noiseLevel: $_POST['noise_level'] !== '' ? (int) $_POST['noise_level'] : null,
            uniqueFeatures: nullableTrim($_POST['unique_features'] ?? null),
            isBlacklisted: $checkbox('is_blacklisted'),
            blacklistReason: nullableTrim($_POST['blacklist_reason'] ?? null),
            lastStayPrice: $_POST['last_stay_price'] !== '' ? (float) $_POST['last_stay_price'] : null,
            currency: trim((string) ($_POST['currency'] ?? 'USD')) ?: 'USD',
            bookingSource: nullableTrim($_POST['booking_source'] ?? null),
            confirmationCode: nullableTrim($_POST['confirmation_code'] ?? null),
            wouldReturn: isset($_POST['would_return']) && $_POST['would_return'] !== '' ? $_POST['would_return'] === '1' : null,
            notes: nullableTrim($_POST['notes'] ?? null),
        );

        try {
            $created = $repo->create($stay, $user->id);

            // Photo upload (optional, single file for simplicity in v1).
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
                            $repo->addPhoto($created->id, $destination, nullableTrim($_POST['photo_caption'] ?? null), $user->id);
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
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Live blacklist warning as the user types (server-rendered check on GET params for progressive enhancement).
if (isset($_GET['check_name'])) {
    $match = $repo->findMatchingBlacklist($user->id, (string) $_GET['check_name'], $_GET['check_city'] ?? null);
    if ($match !== null) {
        $blacklistWarning = "You blacklisted \"{$match->hotelName}\": " . ($match->blacklistReason ?? 'no reason recorded.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexWAYPONT &middot; Log a Hotel Stay</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPONT</a></div>
    <div>
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/list.php">Hotels</a>
        <a href="/hotels/add.php">+ Log a stay</a>
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

        <label>Hotel name<input type="text" name="hotel_name" required></label>
        <label>Brand<input type="text" name="brand"></label>
        <label>Address line 1<input type="text" name="address_line1"></label>
        <label>Address line 2<input type="text" name="address_line2"></label>
        <label>City<input type="text" name="city"></label>
        <label>State / region<input type="text" name="state_region"></label>
        <label>Postal code<input type="text" name="postal_code"></label>
        <label>Country<input type="text" name="country"></label>
        <label>Room number<input type="text" name="room_number"></label>

        <label>Check-in date<input type="date" name="stay_start" required></label>
        <label>Check-out date<input type="date" name="stay_end" required></label>

        <label>Rating (1-5)
            <select name="rating">
                <option value="">—</option>
                <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?>
            </select>
        </label>

        <fieldset>
            <legend>Amenities</legend>
            <div class="checkbox-grid">
                <label><input type="checkbox" name="has_desk" value="1"> Desk suitable for working</label>
                <label><input type="checkbox" name="has_pool" value="1"> Pool</label>
                <label><input type="checkbox" name="has_hot_tub" value="1"> Hot tub</label>
                <label><input type="checkbox" name="has_breakfast" value="1"> Breakfast included</label>
                <label><input type="checkbox" name="has_gym" value="1"> Gym</label>
                <label><input type="checkbox" name="has_free_parking" value="1"> Free parking</label>
                <label><input type="checkbox" name="has_airport_shuttle" value="1"> Airport shuttle</label>
            </div>
        </fieldset>

        <label>Desk notes<input type="text" name="desk_notes"></label>
        <label>Breakfast notes<input type="text" name="breakfast_notes"></label>

        <label>WiFi quality (1-5)
            <select name="wifi_quality">
                <option value="">—</option>
                <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?>
            </select>
        </label>
        <label>Noise level (1 = quiet, 5 = loud)
            <select name="noise_level">
                <option value="">—</option>
                <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?>
            </select>
        </label>

        <label>Unique features<textarea name="unique_features" rows="2"></textarea></label>
        <label>Notes<textarea name="notes" rows="3"></textarea></label>

        <label>Last stay price<input type="number" step="0.01" name="last_stay_price"></label>
        <label>Currency<input type="text" name="currency" value="USD" maxlength="3"></label>
        <label>Booking source<input type="text" name="booking_source"></label>
        <label>Confirmation code<input type="text" name="confirmation_code"></label>

        <label>Would you return?
            <select name="would_return">
                <option value="">—</option>
                <option value="1">Yes</option>
                <option value="0">No</option>
            </select>
        </label>

        <fieldset>
            <legend>Blacklist</legend>
            <label><input type="checkbox" name="is_blacklisted" value="1"> Blacklist this hotel</label>
            <label>Reason<input type="text" name="blacklist_reason"></label>
        </fieldset>

        <label>Photo (optional)<input type="file" name="photo" accept="image/png,image/jpeg,image/webp"></label>
        <label>Photo caption<input type="text" name="photo_caption"></label>

        <button type="submit" class="primary">Save stay</button>
    </form>
</main>
</body>
</html>
