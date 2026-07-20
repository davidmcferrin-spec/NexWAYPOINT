<?php

declare(strict_types=1);

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\OfficeVenueRepository;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$venueRepo = new OfficeVenueRepository($app['db'], $app['logger']);
$geocoder = new Geocoder($app['logger']);
$userRepo = new UserRepository($app['db'], $app['logger']);
$canManageVenues = $userRepo->isAdmin($user);

$properties = $propertyRepo->findForUser($user->id);
$venues = $venueRepo->findActive();

/** Cap live Nominatim lookups per page load (cache hits are free). */
$maxLiveLookups = 12;
$liveLookups = 0;

$mapped = [];
$unmapped = [];
$mappedVenues = [];
$unmappedVenues = [];

// Resolve venues first so office pins are not starved by hotel lookups.
foreach ($venues as $venue) {
    $lat = $venue->latitude;
    $lon = $venue->longitude;
    $approx = false;

    if (($lat === null || $lon === null) && $venue->id !== null) {
        $coords = null;
        $hasAddress = $venue->addressLine1 !== null && trim($venue->addressLine1) !== '';
        $hasCity = $venue->city !== null && trim($venue->city) !== '';
        if ($liveLookups < $maxLiveLookups && ($hasAddress || $hasCity)) {
            $coords = $geocoder->geocode(
                $venue->addressLine1,
                $venue->city,
                $venue->stateRegion,
                $venue->postalCode,
                $venue->country,
                true
            );
            $liveLookups++;
        }
        if ($coords === null && $hasCity && $liveLookups < $maxLiveLookups) {
            $coords = $geocoder->geocodeCity($venue->city, $venue->stateRegion, $venue->country, true);
            $liveLookups++;
            $approx = true;
        }
        if ($coords !== null) {
            $lat = $coords['lat'];
            $lon = $coords['lon'];
            $venueRepo->updateCoordinates((int) $venue->id, $lat, $lon, $user->id);
        }
    }

    $row = [
        'id' => (int) $venue->id,
        'name' => $venue->name,
        'place' => $venue->placeLabel(),
        'notes' => $venue->notes,
        'lat' => $lat,
        'lon' => $lon,
        'approx' => $approx && $venue->latitude === null,
        'url' => $canManageVenues ? '/settings/site.php?edit_venue=' . (int) $venue->id : null,
    ];

    if ($lat !== null && $lon !== null) {
        $mappedVenues[] = $row;
    } else {
        $unmappedVenues[] = $row;
    }
}

foreach ($properties as $property) {
    $lat = $property->latitude;
    $lon = $property->longitude;
    $approx = false;

    if ($lat === null || $lon === null) {
        $coords = null;
        if ($liveLookups < $maxLiveLookups
            && $property->addressLine1 !== null
            && trim($property->addressLine1) !== ''
            && $property->city !== null
            && trim($property->city) !== ''
        ) {
            $coords = $geocoder->geocode(
                $property->addressLine1,
                $property->city,
                $property->stateRegion,
                $property->postalCode,
                $property->country
            );
            $liveLookups++;
        }

        if ($coords === null && $property->city !== null && trim($property->city) !== '') {
            $coords = $geocoder->geocodeCity(
                $property->city,
                $property->stateRegion,
                $property->country
            );
            if ($liveLookups < $maxLiveLookups) {
                $liveLookups++;
            }
            $approx = true;
        }

        if ($coords !== null) {
            $lat = $coords['lat'];
            $lon = $coords['lon'];
            if ($property->id !== null) {
                $propertyRepo->updateCoordinates((int) $property->id, $lat, $lon, $user->id);
            }
        }
    }

    $place = trim(implode(', ', array_filter([
        $property->city,
        $property->stateRegion,
        $property->country,
    ])));

    $row = [
        'id' => (int) $property->id,
        'name' => $property->hotelName,
        'brand' => $property->brand,
        'place' => $place,
        'rating' => $property->overallRating,
        'blacklisted' => $property->isBlacklisted,
        'destination_fee' => $property->hasDestinationFee,
        'lat' => $lat,
        'lon' => $lon,
        'approx' => $approx && $property->latitude === null,
        'url' => '/hotels/edit-property.php?id=' . (int) $property->id,
    ];

    if ($lat !== null && $lon !== null) {
        $mapped[] = $row;
    } else {
        $unmapped[] = $row;
    }
}

$hasAnything = $properties !== [] || $venues !== [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Hotel map</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container map-page">
    <h1>Hotel map</h1>
    <p class="hint">
        Your hotels (circles) and site offices/venues (squares) on OpenStreetMap.
        Pins use saved coordinates when available; otherwise address/city is geocoded (cached) and saved.
        <a href="/hotels/properties.php">Hotel list</a>
        <?php if ($canManageVenues): ?>
            ·
            <a href="/settings/site.php">Manage offices/venues</a>
        <?php endif; ?>
    </p>

    <?php if (!$hasAnything): ?>
        <p class="empty-state">
            Nothing to map yet.
            <a href="/hotels/add.php">Log a stay</a>
            or add an office/venue under Settings.
        </p>
    <?php else: ?>
        <div id="hotel-map" class="hotel-map" role="img" aria-label="Map of hotels and offices"></div>
        <p class="hint">
            <?= count($mapped) ?> hotel<?= count($mapped) === 1 ? '' : 's' ?>
            · <?= count($mappedVenues) ?> office/venue<?= count($mappedVenues) === 1 ? '' : 's' ?>
            <?php if ($unmapped !== [] || $unmappedVenues !== []): ?>
                · <?= count($unmapped) + count($unmappedVenues) ?> without a location yet
            <?php endif; ?>
        </p>
        <p class="hint map-legend">
            <span class="map-legend-swatch map-legend-hotel"></span> Hotel
            <span class="map-legend-swatch map-legend-venue"></span> Office / venue
        </p>

        <?php if ($unmapped !== [] || $unmappedVenues !== []): ?>
            <div class="card">
                <h3>Not on the map</h3>
                <p class="hint">Add a city (and ideally an address) so the location can be geocoded.</p>
                <ul>
                    <?php foreach ($unmapped as $u): ?>
                        <li>
                            Hotel:
                            <a href="/hotels/edit-property.php?id=<?= (int) $u['id'] ?>">
                                <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>
                            </a>
                            <?php if ($u['place'] !== ''): ?>
                                <span class="text-dim">— <?= htmlspecialchars($u['place'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($unmappedVenues as $u): ?>
                        <li>
                            Office/venue:
                            <?php if ($canManageVenues): ?>
                            <a href="/settings/site.php?edit_venue=<?= (int) $u['id'] ?>">
                                <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>
                            </a>
                            <?php else: ?>
                                <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>
                            <?php endif; ?>
                            <?php if ($u['place'] !== ''): ?>
                                <span class="text-dim">— <?= htmlspecialchars($u['place'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
window.NEXWAYPOINT_HOTEL_MAP = <?= json_encode([
    'hotels' => $mapped,
    'venues' => $mappedVenues,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/hotel-map.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
