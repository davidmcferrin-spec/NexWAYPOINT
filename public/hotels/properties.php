<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelBrandRepository;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\OfficeVenueRepository;
use NexWaypoint\Hotels\UserHotelBlacklistRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$blacklistRepo = new UserHotelBlacklistRepository($app['db'], $app['logger']);
$hotelBrandNames = (new HotelBrandRepository($app['db'], $app['logger']))->namesForSelect();
$walkToOfficeVenues = array_values(array_unique(array_merge(
    (new OfficeVenueRepository($app['db'], $app['logger']))->namesForSelect(),
    $propertyRepo->walkToOfficeVenues(),
)));
natcasesort($walkToOfficeVenues);
$walkToOfficeVenues = array_values($walkToOfficeVenues);

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'brand' => trim((string) ($_GET['brand'] ?? '')),
    'city' => trim((string) ($_GET['city'] ?? '')),
    'state_region' => trim((string) ($_GET['state_region'] ?? '')),
    'destination_fee' => (string) ($_GET['destination_fee'] ?? ''),
    'has_desk' => (string) ($_GET['has_desk'] ?? ''),
    'blacklisted' => (string) ($_GET['blacklisted'] ?? ''),
    'teammate_adverse' => (string) ($_GET['teammate_adverse'] ?? ''),
];
$sort = (string) ($_GET['sort'] ?? 'hotel_name');
$allowedSort = ['hotel_name', 'brand', 'city', 'overall_rating', 'updated'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'hotel_name';
}

$properties = $propertyRepo->search($user->id, $filters, $sort);
$locations = $propertyRepo->locations();
$myBlacklistIds = $blacklistRepo->propertyIdsForUser($user->id);

$adverseByPropertyId = [];
foreach ($properties as $property) {
    $adverseByPropertyId[(int) $property->id] = $propertyRepo->findTeammateAdverseForProperty(
        $user->id,
        (int) $property->id
    );
}

$queryBase = static function (array $overrides = []) use ($filters, $sort): string {
    $params = array_merge($filters, ['sort' => $sort], $overrides);
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return '/hotels/properties.php?' . http_build_query($params);
};

$cities = [];
foreach ($locations as $loc) {
    $cities[$loc['city']] = true;
}

$brandOptions = [];
foreach ($hotelBrandNames as $brandName) {
    $brandOptions[$brandName] = true;
}
foreach ($propertyRepo->findAll() as $p) {
    if ($p->brand !== null && trim($p->brand) !== '') {
        $brandOptions[trim($p->brand)] = true;
    }
}
$brandOptions = array_keys($brandOptions);
natcasesort($brandOptions);
$brandOptions = array_values($brandOptions);

$hasActiveFilters = $filters['q'] !== ''
    || $filters['brand'] !== ''
    || $filters['city'] !== ''
    || $filters['state_region'] !== ''
    || $filters['destination_fee'] !== ''
    || $filters['has_desk'] !== ''
    || $filters['blacklisted'] !== ''
    || $filters['teammate_adverse'] !== '';

$sortLabels = [
    'hotel_name' => 'Name',
    'brand' => 'Brand',
    'city' => 'City',
    'overall_rating' => 'Rating',
    'updated' => 'Recently updated',
];

$property = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Hotel Properties</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/property-modal.js'), ENT_QUOTES) ?>" defer></script>
    <script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/address-search.js'), ENT_QUOTES) ?>" defer></script>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container directory-page">
    <header class="directory-header">
        <div>
            <h1>Hotel properties</h1>
            <p class="hint">
                Shared hotel directory. Address lookup fills street + map pins when you add or edit.
                <a href="/hotels/list.php">Stays</a>
                ·
                <a href="/hotels/map.php">Map</a>
            </p>
        </div>
        <button type="button" class="primary" data-open-property-modal>Add hotel</button>
    </header>

    <form method="get" action="/hotels/properties.php" class="directory-filters" id="property-filter-form">
        <div class="directory-filters-primary">
            <label class="directory-filter-grow">
                <span class="visually-hidden">Search hotels</span>
                <input type="search" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>"
                    placeholder="Search hotel name" aria-label="Search hotel name">
            </label>
            <label>
                <span class="visually-hidden">Brand</span>
                <select name="brand" aria-label="Filter by brand" onchange="this.form.submit()">
                    <option value="">All brands</option>
                    <?php foreach ($brandOptions as $brandName): ?>
                        <option value="<?= htmlspecialchars($brandName, ENT_QUOTES) ?>"
                            <?= strcasecmp($filters['brand'], $brandName) === 0 ? 'selected' : '' ?>>
                            <?= htmlspecialchars($brandName, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">City</span>
                <input type="text" name="city" value="<?= htmlspecialchars($filters['city'], ENT_QUOTES) ?>"
                    placeholder="City" list="city-suggestions" aria-label="Filter by city">
            </label>
            <label class="directory-filter-state">
                <span class="visually-hidden">State</span>
                <input type="text" name="state_region" value="<?= htmlspecialchars($filters['state_region'], ENT_QUOTES) ?>"
                    placeholder="ST" aria-label="Filter by state" maxlength="20">
            </label>
            <datalist id="city-suggestions">
                <?php foreach (array_keys($cities) as $c): ?>
                    <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>">
                <?php endforeach; ?>
            </datalist>
            <button type="submit" class="secondary">Filter</button>
        </div>
        <div class="directory-filters-secondary">
            <label>
                Desk
                <select name="has_desk" aria-label="Filter by desk" onchange="this.form.submit()">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['has_desk'] === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $filters['has_desk'] === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </label>
            <label>
                Dest. fee
                <select name="destination_fee" aria-label="Filter by destination charge" onchange="this.form.submit()">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['destination_fee'] === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $filters['destination_fee'] === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </label>
            <label>
                Blacklist
                <select name="blacklisted" aria-label="Filter by my blacklist" onchange="this.form.submit()">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['blacklisted'] === '1' ? 'selected' : '' ?>>Mine</option>
                    <option value="0" <?= $filters['blacklisted'] === '0' ? 'selected' : '' ?>>Not mine</option>
                </select>
            </label>
            <label>
                Teammate
                <select name="teammate_adverse" aria-label="Filter by teammate adverse" onchange="this.form.submit()">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['teammate_adverse'] === '1' ? 'selected' : '' ?>>Adverse</option>
                </select>
            </label>
            <label>
                Sort
                <select name="sort" aria-label="Sort hotels" onchange="this.form.submit()">
                    <?php foreach ($sortLabels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $sort === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($hasActiveFilters): ?>
                <a class="directory-filters-clear" href="/hotels/properties.php">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($properties === []): ?>
        <div class="empty-state">
            No properties match.
            <button type="button" class="primary" data-open-property-modal>Add hotel</button>
            or <a href="/hotels/add.php">log a stay</a>.
        </div>
    <?php else: ?>
        <ul class="property-directory">
            <?php foreach ($properties as $property): ?>
                <?php
                $adverse = $adverseByPropertyId[(int) $property->id] ?? [];
                $metaBits = array_values(array_filter([
                    $property->addressSummary(),
                    $property->locationLabel(),
                ]));
                $adverseTitle = implode('; ', array_map(
                    static fn (array $a) => $a['display_name'] . ': ' . ($a['reason'] ?? 'no reason'),
                    $adverse
                ));
                ?>
                <li class="property-row">
                    <div class="property-row-main">
                        <div class="property-row-title">
                            <a class="property-row-name" href="/hotels/edit-property.php?id=<?= (int) $property->id ?>">
                                <?= htmlspecialchars($property->hotelName, ENT_QUOTES) ?>
                            </a>
                            <?php if ($property->brand): ?>
                                <span class="property-row-brand"><?= htmlspecialchars($property->brand, ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($metaBits !== []): ?>
                            <p class="property-row-meta"><?= htmlspecialchars(implode(' · ', $metaBits), ENT_QUOTES) ?></p>
                        <?php endif; ?>
                        <div class="property-row-chips">
                            <?php if ($property->hasDesk): ?>
                                <span class="chip" title="<?= htmlspecialchars($property->deskNotes ?? 'Desk suitable for working', ENT_QUOTES) ?>">Desk</span>
                            <?php endif; ?>
                            <?php if ($property->hasDestinationFee): ?>
                                <span class="chip chip-warn">Dest. fee</span>
                            <?php endif; ?>
                            <?php if (isset($myBlacklistIds[(int) $property->id])): ?>
                                <span class="chip chip-danger">My blacklist</span>
                            <?php endif; ?>
                            <?php if ($adverse !== []): ?>
                                <span class="chip chip-warn" title="<?= htmlspecialchars($adverseTitle, ENT_QUOTES) ?>">
                                    <?= count($adverse) ?> teammate<?= count($adverse) === 1 ? '' : 's' ?> adverse
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($adverse !== []): ?>
                            <p class="property-row-adverse hint">
                                <?php foreach ($adverse as $i => $a): ?>
                                    <?= $i > 0 ? '; ' : '' ?>
                                    <strong><?= htmlspecialchars($a['display_name'], ENT_QUOTES) ?></strong>
                                    <?= htmlspecialchars($a['reason'] !== null && $a['reason'] !== '' ? ' — ' . $a['reason'] : '', ENT_QUOTES) ?>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="property-row-side">
                        <div class="property-row-rating" title="Public average of stay ratings">
                            <?php if ($property->overallRating !== null): ?>
                                <span class="property-row-rating-value"><?= number_format($property->overallRating, 1) ?></span>
                                <span class="property-row-rating-star" aria-hidden="true">★</span>
                            <?php else: ?>
                                <span class="property-row-rating-empty">No rating</span>
                            <?php endif; ?>
                        </div>
                        <div class="property-row-actions">
                            <a href="/hotels/edit-property.php?id=<?= (int) $property->id ?>">Edit</a>
                            <a href="/hotels/add.php?property_id=<?= (int) $property->id ?>">Log stay</a>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="hint directory-count"><?= count($properties) ?> propert<?= count($properties) === 1 ? 'y' : 'ies' ?></p>
    <?php endif; ?>
</main>

<div id="property-modal" class="modal-backdrop" hidden data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
    <div class="modal-panel" role="dialog" aria-labelledby="property-modal-title">
        <h2 id="property-modal-title">Add hotel</h2>
        <p id="property-modal-error" class="alert alert-error" hidden></p>
        <form id="property-modal-form" class="stack">
            <?php
            $property = null;
            require __DIR__ . '/_property_form_fields.php';
            ?>
            <div class="modal-actions">
                <button type="submit" class="primary">Save hotel</button>
                <button type="button" class="secondary" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
