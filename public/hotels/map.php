<?php

declare(strict_types=1);

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$geocoder = new Geocoder($app['logger']);

$properties = $propertyRepo->findForUser($user->id);

/** Cap live Nominatim lookups per page load (cache hits are free). */
$maxLiveLookups = 8;
$liveLookups = 0;

$mapped = [];
$unmapped = [];

foreach ($properties as $property) {
    $lat = $property->latitude;
    $lon = $property->longitude;
    $approx = false;

    if ($lat === null || $lon === null) {
        $coords = null;
        // Prefer street-level when we have an address and budget remains.
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
            // City lookups are usually cache hits after the first; only count
            // toward the budget when we still have room (geocoder self-throttles).
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

    // view.php might use id= — check
    if ($lat !== null && $lon !== null) {
        $mapped[] = $row;
    } else {
        $unmapped[] = $row;
    }
}
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
        Your properties on OpenStreetMap.
        Pins use saved coordinates when available; otherwise city/address is geocoded (cached) and saved back to the property.
        <a href="/hotels/properties.php">List view</a>
    </p>

    <?php if ($properties === []): ?>
        <p class="empty-state">No hotel properties yet. <a href="/hotels/add.php">Log a stay</a> to create one.</p>
    <?php else: ?>
        <div id="hotel-map" class="hotel-map" role="img" aria-label="Map of hotel properties"></div>
        <p class="hint"><?= count($mapped) ?> plotted<?php if ($unmapped !== []): ?>, <?= count($unmapped) ?> without a location yet<?php endif; ?>.</p>

        <?php if ($unmapped !== []): ?>
            <div class="card">
                <h3>Not on the map</h3>
                <p class="hint">Add a city (and ideally an address) on the property so it can be geocoded.</p>
                <ul>
                    <?php foreach ($unmapped as $u): ?>
                        <li>
                            <a href="/hotels/edit-property.php?id=<?= (int) $u['id'] ?>">
                                <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>
                            </a>
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
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/hotel-map.js" defer></script>
</body>
</html>
