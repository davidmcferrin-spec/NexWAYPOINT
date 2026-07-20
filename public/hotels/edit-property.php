<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelBrandRepository;
use NexWaypoint\Hotels\HotelProperty;
use NexWaypoint\Hotels\HotelPropertyRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$repo = new HotelPropertyRepository($app['db'], $app['logger']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$property = $repo->find($id);

if ($property === null || $property->userId !== $user->id) {
    http_response_code(404);
    echo 'Property not found.';
    exit;
}

$hotelBrandNames = (new HotelBrandRepository($app['db'], $app['logger']))->namesForSelect($property->brand);
$walkToOfficeVenues = $repo->walkToOfficeVenuesForUser($user->id);

$errors = [];
$message = null;

$nullable = static function (?string $value): ?string {
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
};

$checkbox = static fn (string $key): bool => isset($_POST[$key]) && $_POST[$key] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        try {
            $hotelName = trim((string) ($_POST['hotel_name'] ?? ''));
            $city = $nullable($_POST['city'] ?? null);
            if ($hotelName === '') {
                throw new InvalidArgumentException('Hotel name is required.');
            }
            if ($city === null) {
                throw new InvalidArgumentException('City is required.');
            }

            $property = $repo->update(new HotelProperty(
                id: $property->id,
                userId: $user->id,
                hotelName: $hotelName,
                brand: $nullable($_POST['brand'] ?? null),
                addressLine1: $nullable($_POST['address_line1'] ?? null),
                addressLine2: $nullable($_POST['address_line2'] ?? null),
                city: $city,
                stateRegion: $nullable($_POST['state_region'] ?? null),
                postalCode: $nullable($_POST['postal_code'] ?? null),
                country: $nullable($_POST['country'] ?? null),
                phone: $nullable($_POST['phone'] ?? null),
                latitude: $property->latitude,
                longitude: $property->longitude,
                hasDesk: $checkbox('has_desk'),
                deskNotes: $nullable($_POST['desk_notes'] ?? null),
                hasPool: $checkbox('has_pool'),
                hasHotTub: $checkbox('has_hot_tub'),
                hasBreakfast: $checkbox('has_breakfast'),
                breakfastNotes: $nullable($_POST['breakfast_notes'] ?? null),
                hasGym: $checkbox('has_gym'),
                hasFreeParking: $checkbox('has_free_parking'),
                hasAirportShuttle: $checkbox('has_airport_shuttle'),
                hasEvCharging: $checkbox('has_ev_charging'),
                hasOnsiteRestaurant: $checkbox('has_onsite_restaurant'),
                hasOffsiteGym: $checkbox('has_offsite_gym'),
                walkToOffice: $checkbox('walk_to_office') || $nullable($_POST['walk_to_office_notes'] ?? null) !== null,
                walkToOfficeNotes: $nullable($_POST['walk_to_office_notes'] ?? null),
                hasDestinationFee: $checkbox('has_destination_fee'),
                destinationFeeNotes: null,
                wifiQuality: $_POST['wifi_quality'] !== '' ? (int) $_POST['wifi_quality'] : null,
                noiseLevel: $_POST['noise_level'] !== '' ? (int) $_POST['noise_level'] : null,
                uniqueFeatures: $nullable($_POST['unique_features'] ?? null),
                isBlacklisted: $checkbox('is_blacklisted'),
                blacklistReason: $nullable($_POST['blacklist_reason'] ?? null),
                overallRating: $property->overallRating,
            ), $user->id);
            $message = 'Property updated.';
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$prefix = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Edit property</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <p><a href="/hotels/properties.php">&larr; Back to hotels</a></p>
    <h1>Edit property</h1>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <form class="stack" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
        <?php require __DIR__ . '/_property_form_fields.php'; ?>
        <button type="submit" class="primary">Save property</button>
    </form>
</main>
</body>
</html>
