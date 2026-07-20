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
    'city' => trim((string) ($_GET['city'] ?? '')),
    'state_region' => trim((string) ($_GET['state_region'] ?? '')),
    'destination_fee' => (string) ($_GET['destination_fee'] ?? ''),
    'blacklisted' => (string) ($_GET['blacklisted'] ?? ''),
    'teammate_adverse' => (string) ($_GET['teammate_adverse'] ?? ''),
];
$sort = (string) ($_GET['sort'] ?? 'hotel_name');
$allowedSort = ['hotel_name', 'city', 'overall_rating', 'updated'];
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

$hasActiveFilters = $filters['q'] !== ''
    || $filters['city'] !== ''
    || $filters['state_region'] !== ''
    || $filters['destination_fee'] !== ''
    || $filters['blacklisted'] !== ''
    || $filters['teammate_adverse'] !== '';

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
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <div class="modal-actions" style="justify-content:space-between;align-items:center;margin-bottom:0.5rem">
        <h1 style="margin:0">Hotel properties</h1>
        <button type="button" class="primary" data-open-property-modal>Add hotel</button>
    </div>
    <p class="hint">
        Blacklist is yours to set; teammate adverse preferences for the same hotel/city are shown.
        <a href="/hotels/list.php">Stays</a>
        ·
        <a href="/hotels/map.php">Map</a>
        <?php if ($hasActiveFilters): ?>
            ·
            <a href="/hotels/properties.php">Clear filters</a>
        <?php endif; ?>
    </p>

    <form method="get" action="/hotels/properties.php" id="property-filter-form">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES) ?>">
        <table class="filterable-table">
            <thead>
                <tr>
                    <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'hotel_name']), ENT_QUOTES) ?>">Hotel</a></th>
                    <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'city']), ENT_QUOTES) ?>">Location</a></th>
                    <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'overall_rating']), ENT_QUOTES) ?>">Overall</a></th>
                    <th>Dest. charge</th>
                    <th>Flags</th>
                    <th></th>
                </tr>
                <tr class="table-filters">
                    <th>
                        <input type="search" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="Name / brand" aria-label="Filter by hotel name">
                    </th>
                    <th>
                        <div class="table-filter-pair">
                            <input type="text" name="city" value="<?= htmlspecialchars($filters['city'], ENT_QUOTES) ?>" placeholder="City" list="city-suggestions" aria-label="Filter by city">
                            <input type="text" name="state_region" value="<?= htmlspecialchars($filters['state_region'], ENT_QUOTES) ?>" placeholder="ST" aria-label="Filter by state" maxlength="20">
                        </div>
                        <datalist id="city-suggestions">
                            <?php foreach (array_keys($cities) as $c): ?>
                                <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </th>
                    <th></th>
                    <th>
                        <select name="destination_fee" aria-label="Filter by destination charge" onchange="this.form.submit()">
                            <option value="">Any</option>
                            <option value="1" <?= $filters['destination_fee'] === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= $filters['destination_fee'] === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </th>
                    <th>
                        <div class="table-filter-pair">
                            <select name="blacklisted" aria-label="Filter by my blacklist" onchange="this.form.submit()">
                                <option value="">Blacklist</option>
                                <option value="1" <?= $filters['blacklisted'] === '1' ? 'selected' : '' ?>>Mine</option>
                                <option value="0" <?= $filters['blacklisted'] === '0' ? 'selected' : '' ?>>Not mine</option>
                            </select>
                            <select name="teammate_adverse" aria-label="Filter by teammate adverse" onchange="this.form.submit()">
                                <option value="">Adverse</option>
                                <option value="1" <?= $filters['teammate_adverse'] === '1' ? 'selected' : '' ?>>Teammate</option>
                            </select>
                        </div>
                    </th>
                    <th>
                        <button type="submit" class="secondary table-filter-go">Go</button>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if ($properties === []): ?>
                    <tr>
                        <td colspan="6" class="empty-state">
                            No properties match.
                            <button type="button" class="primary" data-open-property-modal>Add hotel</button>
                            or <a href="/hotels/add.php">log a stay</a>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($properties as $property): ?>
                        <?php $adverse = $adverseByPropertyId[(int) $property->id] ?? []; ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($property->hotelName, ENT_QUOTES) ?>
                                <?php if ($property->brand): ?>
                                    <span class="hint"><?= htmlspecialchars($property->brand, ENT_QUOTES) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($property->locationLabel() ?? '—', ENT_QUOTES) ?></td>
                            <td>
                                <?= $property->overallRating !== null ? number_format($property->overallRating, 1) . '★' : '—' ?>
                            </td>
                            <td>
                                <?= $property->hasDestinationFee ? 'Yes' : '—' ?>
                            </td>
                            <td>
                                <?php if (isset($myBlacklistIds[(int) $property->id])): ?>
                                    <span class="badge badge-blacklist">My blacklist</span>
                                <?php endif; ?>
                                <?php if ($adverse !== []): ?>
                                    <span class="badge badge-status-delay" title="<?= htmlspecialchars(implode('; ', array_map(
                                        static fn (array $a) => $a['display_name'] . ': ' . ($a['reason'] ?? 'no reason'),
                                        $adverse
                                    )), ENT_QUOTES) ?>">
                                        <?= count($adverse) ?> teammate<?= count($adverse) === 1 ? '' : 's' ?> adverse
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/hotels/edit-property.php?id=<?= (int) $property->id ?>">Edit</a>
                                ·
                                <a href="/hotels/add.php?property_id=<?= (int) $property->id ?>">Log stay</a>
                            </td>
                        </tr>
                        <?php if ($adverse !== []): ?>
                            <tr>
                                <td colspan="6" class="hint">
                                    Teammate adverse preference<?= count($adverse) === 1 ? '' : 's' ?>:
                                    <?php foreach ($adverse as $i => $a): ?>
                                        <?= $i > 0 ? '; ' : '' ?>
                                        <strong><?= htmlspecialchars($a['display_name'], ENT_QUOTES) ?></strong>
                                        <?= htmlspecialchars($a['reason'] !== null && $a['reason'] !== '' ? ' — ' . $a['reason'] : '', ENT_QUOTES) ?>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </form>
    <?php if ($properties !== []): ?>
        <p class="hint"><?= count($properties) ?> propert<?= count($properties) === 1 ? 'y' : 'ies' ?></p>
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
