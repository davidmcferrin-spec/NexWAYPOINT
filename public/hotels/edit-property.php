<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelBrandRepository;
use NexWaypoint\Hotels\HotelProperty;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\OfficeVenueRepository;
use NexWaypoint\Hotels\UserHotelBlacklistRepository;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$userRepo = new UserRepository($app['db'], $app['logger']);
$isAdmin = $userRepo->isAdmin($user);

$repo = new HotelPropertyRepository($app['db'], $app['logger']);
$blacklistRepo = new UserHotelBlacklistRepository($app['db'], $app['logger']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$property = $repo->find($id);

if ($property === null) {
    http_response_code(404);
    echo 'Property not found.';
    exit;
}

$myBlacklisted = $blacklistRepo->isBlacklisted($user->id, (int) $property->id);
$myBlacklistReason = $blacklistRepo->reason($user->id, (int) $property->id);

$hotelBrandNames = (new HotelBrandRepository($app['db'], $app['logger']))->namesForSelect($property->brand);
$walkToOfficeVenues = array_values(array_unique(array_merge(
    (new OfficeVenueRepository($app['db'], $app['logger']))->namesForSelect(),
    $repo->walkToOfficeVenues(),
)));
natcasesort($walkToOfficeVenues);
$walkToOfficeVenues = array_values($walkToOfficeVenues);

$errors = [];
$message = null;
$stayCount = $repo->countStays((int) $property->id);

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
    } elseif (($_POST['action'] ?? '') === 'delete') {
        if (!$isAdmin) {
            $errors[] = 'Only a site admin can delete a hotel.';
        } else {
            $repo->delete((int) $property->id, $user->id);
            header('Location: /hotels/properties.php');
            exit;
        }
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

            $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' && is_numeric($_POST['latitude'])
                ? (float) $_POST['latitude'] : $property->latitude;
            $lon = isset($_POST['longitude']) && $_POST['longitude'] !== '' && is_numeric($_POST['longitude'])
                ? (float) $_POST['longitude'] : $property->longitude;

            $property = $repo->update(new HotelProperty(
                id: $property->id,
                createdByUserId: $property->createdByUserId,
                hotelName: $hotelName,
                brand: $nullable($_POST['brand'] ?? null),
                addressLine1: $nullable($_POST['address_line1'] ?? null),
                addressLine2: $nullable($_POST['address_line2'] ?? null),
                city: $city,
                stateRegion: $nullable($_POST['state_region'] ?? null),
                postalCode: $nullable($_POST['postal_code'] ?? null),
                country: $nullable($_POST['country'] ?? null),
                phone: $nullable($_POST['phone'] ?? null),
                latitude: $lat,
                longitude: $lon,
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
                overallRating: $property->overallRating,
            ), $user->id);

            $blacklistRepo->set(
                $user->id,
                (int) $property->id,
                $checkbox('is_blacklisted'),
                $nullable($_POST['blacklist_reason'] ?? null),
                $user->id,
            );
            $myBlacklisted = $blacklistRepo->isBlacklisted($user->id, (int) $property->id);
            $myBlacklistReason = $blacklistRepo->reason($user->id, (int) $property->id);
            $message = 'Property updated.';
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$prefix = '';
// Form fields still use isBlacklisted / blacklistReason for the current user's preference.
$propertyForForm = $property;
$formIsBlacklisted = $myBlacklisted;
$formBlacklistReason = $myBlacklistReason;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Edit property</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/address-search.js'), ENT_QUOTES) ?>" defer></script>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <p><a href="/hotels/properties.php">&larr; Back to hotels</a></p>
    <h1>Edit property</h1>
    <p class="hint">Identity and amenities are shared site-wide. Blacklist is your personal preference only. Overall rating averages everyone’s stay ratings.</p>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <form class="stack" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
        <?php
        $property = $propertyForForm;
        $overrideBlacklist = ['isBlacklisted' => $formIsBlacklisted, 'blacklistReason' => $formBlacklistReason];
        require __DIR__ . '/_property_form_fields.php';
        ?>
        <button type="submit" class="primary">Save property</button>
    </form>

    <?php if ($isAdmin): ?>
    <form class="stack" method="post" style="margin-top: 2rem"
        onsubmit="return confirm(<?= htmlspecialchars(json_encode(
            $stayCount === 0
                ? 'Permanently delete this hotel for everyone? This cannot be undone.'
                : "Permanently delete this hotel and its {$stayCount} stay" . ($stayCount === 1 ? '' : 's') . ' for all users? This cannot be undone.'
        ), ENT_QUOTES) ?>);">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="delete">
        <p class="hint">
            Delete removes the hotel from the shared directory
            <?php if ($stayCount > 0): ?>
                and permanently deletes <?= (int) $stayCount ?> linked stay<?= $stayCount === 1 ? '' : 's' ?>
            <?php endif; ?>.
            Use your personal blacklist if you only want to flag it for yourself.
        </p>
        <button type="submit" class="danger">Delete hotel</button>
    </form>
    <?php endif; ?>
</main>
</body>
</html>
