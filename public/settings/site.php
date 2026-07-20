<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelBrandRepository;
use NexWaypoint\Hotels\OfficeVenueRepository;
use NexWaypoint\Trips\Carrier;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$userRepo = new UserRepository($app['db'], $app['logger']);

if (!$userRepo->isAdmin($user)) {
    http_response_code(403);
    echo 'Site admins only.';
    exit;
}

$settingsSection = 'site';

$brandRepo = new HotelBrandRepository($app['db'], $app['logger']);
$venueRepo = new OfficeVenueRepository($app['db'], $app['logger']);
$carrierRepo = new CarrierRepository($app['db'], $app['logger']);
$geocoder = new Geocoder($app['logger']);

$errors = [];
$message = null;
$brandWarning = !$brandRepo->tableReady()
    ? 'Database is missing hotel_brands. On the server run: php scripts/migrate.php'
    : null;
$venueWarning = !$venueRepo->tableReady()
    ? 'Database is missing office_venues. On the server run: php scripts/migrate.php'
    : null;
$carrierWarning = !$app['db']->tableExists('carriers')
    ? 'Database is missing carriers. On the server run: php scripts/migrate.php'
    : null;

$editVenueId = isset($_GET['venue_id']) ? (int) $_GET['venue_id'] : (isset($_GET['edit_venue']) ? (int) $_GET['edit_venue'] : 0);
$editingVenue = $editVenueId > 0 ? $venueRepo->find($editVenueId) : null;

$nullable = static function (mixed $v): ?string {
    $v = trim((string) ($v ?? ''));
    return $v === '' ? null : $v;
};

$geocodeVenue = static function (
    ?string $addressLine1,
    ?string $city,
    ?string $stateRegion,
    ?string $postalCode,
    ?string $country,
) use ($geocoder): array {
    $normalizedStreet = $geocoder->normalizeStreetAddress($addressLine1);
    $coords = $geocoder->geocode($normalizedStreet ?? $addressLine1, $city, $stateRegion, $postalCode, $country, true);
    // Never fall back to city centroid when a street was provided — that pinned
    // Washington, DC at the White House for "Capital" vs "Capitol" typos.
    if ($coords === null && ($normalizedStreet ?? $addressLine1) === null && $city !== null) {
        $coords = $geocoder->geocodeCity($city, $stateRegion, $country, true);
    }
    return [
        $normalizedStreet ?? $addressLine1,
        $coords['lat'] ?? null,
        $coords['lon'] ?? null,
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'add_brand') {
                if ($brandWarning !== null) {
                    throw new RuntimeException($brandWarning);
                }
                $created = $brandRepo->create((string) ($_POST['name'] ?? ''), $user->id);
                $message = "Added brand {$created->name}.";
            } elseif ($action === 'remove_brand') {
                if ($brandWarning !== null) {
                    throw new RuntimeException($brandWarning);
                }
                $brandRepo->delete((int) ($_POST['brand_id'] ?? 0), $user->id);
                $message = 'Brand removed from the dropdown (existing properties keep their stored brand).';
            } elseif ($action === 'add_venue' || $action === 'update_venue') {
                if ($venueWarning !== null) {
                    throw new RuntimeException($venueWarning);
                }
                $a1 = $nullable($_POST['address_line1'] ?? null);
                $city = $nullable($_POST['city'] ?? null);
                $state = $nullable($_POST['state_region'] ?? null);
                $postal = $nullable($_POST['postal_code'] ?? null);
                $country = $nullable($_POST['country'] ?? null) ?? 'USA';
                [$a1, $lat, $lon] = $geocodeVenue($a1, $city, $state, $postal, $country);

                if ($action === 'add_venue') {
                    $created = $venueRepo->create(
                        (string) ($_POST['name'] ?? ''),
                        $a1,
                        $city,
                        $state,
                        $postal,
                        $country,
                        $nullable($_POST['notes'] ?? null),
                        $lat,
                        $lon,
                        $user->id,
                    );
                    $geoNote = ($lat !== null)
                        ? ' Plotted on the map.'
                        : ' Could not geocode yet — check city/state/country, then save again.';
                    $message = "Added office/venue {$created->name}." . $geoNote;
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    $existing = $venueRepo->find($id);
                    if ($existing === null) {
                        throw new InvalidArgumentException('Office / venue not found.');
                    }
                    $venueRepo->update(
                        $id,
                        (string) ($_POST['name'] ?? ''),
                        $a1,
                        $city,
                        $state,
                        $postal,
                        $country,
                        $nullable($_POST['notes'] ?? null),
                        isset($_POST['is_active']),
                        $lat,
                        $lon,
                        $user->id,
                    );
                    $geoNote = ($lat !== null)
                        ? ' Map pin updated.'
                        : ' Could not geocode — check city/state/country.';
                    $message = 'Office / venue updated.' . $geoNote;
                    $editingVenue = null;
                    $editVenueId = 0;
                }
            } elseif ($action === 'remove_venue') {
                if ($venueWarning !== null) {
                    throw new RuntimeException($venueWarning);
                }
                $venueRepo->deactivate((int) ($_POST['venue_id'] ?? 0), $user->id);
                $message = 'Office / venue deactivated.';
                $editingVenue = null;
            } elseif ($action === 'save_carrier') {
                if ($carrierWarning !== null) {
                    throw new RuntimeException($carrierWarning);
                }
                $type = (string) ($_POST['carrier_type'] ?? Carrier::TYPE_AIRLINE);
                if (!in_array($type, [Carrier::TYPE_AIRLINE, Carrier::TYPE_RAIL], true)) {
                    throw new InvalidArgumentException('Invalid carrier type.');
                }
                $name = trim((string) ($_POST['name'] ?? ''));
                $iata = strtoupper(trim((string) ($_POST['iata_code'] ?? '')));
                $id = (int) ($_POST['id'] ?? 0);
                $payload = new Carrier(
                    id: $id > 0 ? $id : null,
                    userId: $user->id,
                    name: $name,
                    iataCode: $iata !== '' ? $iata : null,
                    carrierType: $type,
                );
                if ($id > 0) {
                    $existing = $carrierRepo->find($id);
                    if ($existing === null || $existing->carrierType !== $type) {
                        throw new InvalidArgumentException('Carrier not found.');
                    }
                    $carrierRepo->update(new Carrier(
                        id: $id,
                        userId: $existing->userId,
                        name: $name,
                        iataCode: $iata !== '' ? $iata : null,
                        carrierType: $type,
                    ), $user->id);
                    $message = $type === Carrier::TYPE_RAIL ? 'Rail operator updated.' : 'Carrier updated.';
                } else {
                    $carrierRepo->create($payload, $user->id);
                    $message = $type === Carrier::TYPE_RAIL ? 'Rail operator added.' : 'Carrier added.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$brands = $brandWarning === null ? $brandRepo->findActive() : [];
$venues = $venueWarning === null ? $venueRepo->findAll() : [];
$airlines = $carrierWarning === null ? $carrierRepo->findByType(Carrier::TYPE_AIRLINE) : [];
$rails = $carrierWarning === null ? $carrierRepo->findByType(Carrier::TYPE_RAIL) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Site catalogs</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>Site catalogs</h1>
    <p>Shared lists used across the site (carriers, venues, brands).</p>

    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card" id="airline-carriers">
        <div class="card-header-row">
            <h3>Airline carriers</h3>
            <?php if ($carrierWarning === null): ?>
                <button type="button" class="primary" data-open-modal="carrier-modal"
                    data-carrier-type="airline" data-title="Add carrier">Add carrier</button>
            <?php endif; ?>
        </div>
        <p class="hint">Shared IATA list for flight entry and mail import.</p>
        <?php if ($carrierWarning !== null): ?>
            <p class="alert alert-error"><?= htmlspecialchars($carrierWarning, ENT_QUOTES) ?></p>
        <?php elseif ($airlines === []): ?>
            <p class="empty-state">No airline carriers yet.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Name</th><th>IATA</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($airlines as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c->name, ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($c->iataCode ?? '—', ENT_QUOTES) ?></td>
                        <td>
                            <button type="button" class="linkish" data-open-modal="carrier-modal"
                                data-carrier-type="airline"
                                data-title="Edit carrier"
                                data-id="<?= (int) $c->id ?>"
                                data-name="<?= htmlspecialchars($c->name, ENT_QUOTES) ?>"
                                data-iata="<?= htmlspecialchars($c->iataCode ?? '', ENT_QUOTES) ?>">Edit</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card" id="rail-operators">
        <div class="card-header-row">
            <h3>Rail operators</h3>
            <?php if ($carrierWarning === null): ?>
                <button type="button" class="primary" data-open-modal="carrier-modal"
                    data-carrier-type="rail" data-title="Add rail operator">Add operator</button>
            <?php endif; ?>
        </div>
        <p class="hint">Shared list for train segments (e.g. Amtrak). Code optional.</p>
        <?php if ($carrierWarning !== null): ?>
            <p class="alert alert-error"><?= htmlspecialchars($carrierWarning, ENT_QUOTES) ?></p>
        <?php elseif ($rails === []): ?>
            <p class="empty-state">No rail operators yet.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Name</th><th>Code</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rails as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c->name, ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($c->iataCode ?? '—', ENT_QUOTES) ?></td>
                        <td>
                            <button type="button" class="linkish" data-open-modal="carrier-modal"
                                data-carrier-type="rail"
                                data-title="Edit rail operator"
                                data-id="<?= (int) $c->id ?>"
                                data-name="<?= htmlspecialchars($c->name, ENT_QUOTES) ?>"
                                data-iata="<?= htmlspecialchars($c->iataCode ?? '', ENT_QUOTES) ?>">Edit</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card" id="offices-venues">
        <div class="card-header-row">
            <h3>Offices &amp; venues</h3>
            <?php if ($venueWarning === null): ?>
                <button type="button" class="primary" data-open-modal="venue-modal"
                    data-title="Add office / venue">Add venue</button>
            <?php endif; ?>
        </div>
        <p class="hint">
            Work locations for the walk-to field and
            <a href="/hotels/map.php">hotel map</a> pins. Saving re-geocodes the address for the map.
        </p>

        <?php if ($venueWarning !== null): ?>
            <p class="alert alert-error"><?= htmlspecialchars($venueWarning, ENT_QUOTES) ?></p>
        <?php elseif ($venues === []): ?>
            <p class="empty-state">No offices/venues yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Address</th>
                        <th>Map</th>
                        <th>Active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($venues as $venue): ?>
                    <tr>
                        <td><?= htmlspecialchars($venue->name, ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($venue->placeLabel() !== '' ? $venue->placeLabel() : '—', ENT_QUOTES) ?></td>
                        <td><?= ($venue->latitude !== null && $venue->longitude !== null) ? 'yes' : 'no' ?></td>
                        <td><?= $venue->isActive ? 'yes' : 'no' ?></td>
                        <td>
                            <button type="button" class="linkish" data-open-modal="venue-modal"
                                data-title="Edit office / venue"
                                data-id="<?= (int) $venue->id ?>"
                                data-name="<?= htmlspecialchars($venue->name, ENT_QUOTES) ?>"
                                data-address="<?= htmlspecialchars($venue->addressLine1 ?? '', ENT_QUOTES) ?>"
                                data-city="<?= htmlspecialchars($venue->city ?? '', ENT_QUOTES) ?>"
                                data-state="<?= htmlspecialchars($venue->stateRegion ?? '', ENT_QUOTES) ?>"
                                data-postal="<?= htmlspecialchars($venue->postalCode ?? '', ENT_QUOTES) ?>"
                                data-country="<?= htmlspecialchars($venue->country, ENT_QUOTES) ?>"
                                data-notes="<?= htmlspecialchars($venue->notes ?? '', ENT_QUOTES) ?>"
                                data-active="<?= $venue->isActive ? '1' : '0' ?>">Edit</button>
                            <?php if ($venue->isActive && $venue->id !== null): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                    <input type="hidden" name="action" value="remove_venue">
                                    <input type="hidden" name="venue_id" value="<?= (int) $venue->id ?>">
                                    <button type="submit" class="danger">Deactivate</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card" id="hotel-brands">
        <div class="card-header-row">
            <h3>Hotel brands</h3>
            <?php if ($brandWarning === null): ?>
                <button type="button" class="primary" data-open-modal="brand-modal">Add brand</button>
            <?php endif; ?>
        </div>
        <p class="hint">Brand dropdown for hotel properties. Defaults: Marriott, Hilton, IHG, Hyatt, Choice Hotels.</p>

        <?php if ($brandWarning !== null): ?>
            <p class="alert alert-error"><?= htmlspecialchars($brandWarning, ENT_QUOTES) ?></p>
        <?php elseif ($brands === []): ?>
            <p class="empty-state">No active brands.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Brand</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($brands as $brand): ?>
                    <tr>
                        <td><?= htmlspecialchars($brand->name, ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($brand->id !== null): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                <input type="hidden" name="action" value="remove_brand">
                                <input type="hidden" name="brand_id" value="<?= (int) $brand->id ?>">
                                <button type="submit" class="danger">Remove</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>

<div id="carrier-modal" class="modal-backdrop" hidden>
    <div class="modal-panel" role="dialog" aria-labelledby="carrier-modal-title">
        <h2 id="carrier-modal-title">Add carrier</h2>
        <form method="post" class="stack" id="carrier-modal-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="save_carrier">
            <input type="hidden" name="id" id="carrier-modal-id" value="0">
            <input type="hidden" name="carrier_type" id="carrier-modal-type" value="airline">
            <label>Name
                <input type="text" name="name" id="carrier-modal-name" required maxlength="100">
            </label>
            <label><span id="carrier-modal-iata-label-text">IATA code</span>
                <input type="text" name="iata_code" id="carrier-modal-iata" maxlength="3" style="text-transform:uppercase">
            </label>
            <p class="hint" id="carrier-modal-iata-hint">Required for airlines (FlightAware ident).</p>
            <div class="modal-actions">
                <button type="submit" class="primary">Save</button>
                <button type="button" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="brand-modal" class="modal-backdrop" hidden>
    <div class="modal-panel" role="dialog" aria-labelledby="brand-modal-title">
        <h2 id="brand-modal-title">Add brand</h2>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="add_brand">
            <label>Brand name
                <input type="text" name="name" required maxlength="100" placeholder="e.g. Wyndham">
            </label>
            <div class="modal-actions">
                <button type="submit" class="primary">Add brand</button>
                <button type="button" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="venue-modal" class="modal-backdrop" hidden
    <?php if ($editingVenue !== null): ?>data-autoload="1"<?php endif; ?>>
    <div class="modal-panel" role="dialog" aria-labelledby="venue-modal-title">
        <h2 id="venue-modal-title">Add office / venue</h2>
        <form method="post" class="stack" id="venue-modal-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" id="venue-modal-action" value="<?= $editingVenue !== null ? 'update_venue' : 'add_venue' ?>">
            <input type="hidden" name="id" id="venue-modal-id" value="<?= (int) ($editingVenue->id ?? 0) ?>">
            <label>Name
                <input type="text" name="name" id="venue-modal-name" required maxlength="150"
                    placeholder="e.g. NewsNation bureau — Chicago"
                    value="<?= htmlspecialchars($editingVenue->name ?? '', ENT_QUOTES) ?>">
            </label>
            <label>Street address
                <input type="text" name="address_line1" id="venue-modal-address" maxlength="255"
                    value="<?= htmlspecialchars($editingVenue->addressLine1 ?? '', ENT_QUOTES) ?>">
            </label>
            <div class="checkbox-grid">
                <label>City
                    <input type="text" name="city" id="venue-modal-city" maxlength="100"
                        value="<?= htmlspecialchars($editingVenue->city ?? '', ENT_QUOTES) ?>">
                </label>
                <label>State / region
                    <input type="text" name="state_region" id="venue-modal-state" maxlength="100"
                        value="<?= htmlspecialchars($editingVenue->stateRegion ?? '', ENT_QUOTES) ?>">
                </label>
                <label>Postal code
                    <input type="text" name="postal_code" id="venue-modal-postal" maxlength="20"
                        value="<?= htmlspecialchars($editingVenue->postalCode ?? '', ENT_QUOTES) ?>">
                </label>
                <label>Country
                    <input type="text" name="country" id="venue-modal-country" maxlength="100"
                        value="<?= htmlspecialchars($editingVenue->country ?? 'USA', ENT_QUOTES) ?>">
                </label>
            </div>
            <label>Notes
                <input type="text" name="notes" id="venue-modal-notes" maxlength="255" placeholder="Optional"
                    value="<?= htmlspecialchars($editingVenue->notes ?? '', ENT_QUOTES) ?>">
            </label>
            <label id="venue-modal-active-wrap"<?= $editingVenue === null ? ' hidden' : '' ?>>
                <input type="checkbox" name="is_active" id="venue-modal-active" value="1"
                    <?= ($editingVenue === null || $editingVenue->isActive) ? 'checked' : '' ?>>
                Active (show in suggestions and on map)
            </label>
            <p class="hint">Saving looks up coordinates for the map (needs at least a city).</p>
            <div class="modal-actions">
                <button type="submit" class="primary" id="venue-modal-submit">Save venue</button>
                <button type="button" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/site-settings.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
