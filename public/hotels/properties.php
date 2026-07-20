<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelBrandRepository;
use NexWaypoint\Hotels\HotelProperty;
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

// Brands for filter: catalog + any in-use brands not in the catalog.
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

$formatAddress = static function (HotelProperty $property): string {
    $parts = array_values(array_filter([
        $property->addressSummary(),
        $property->locationLabel(),
    ]));
    return $parts !== [] ? implode(', ', $parts) : '—';
};

$formatStars = static function (?float $rating): string {
    if ($rating === null) {
        return '—';
    }
    $filled = (int) max(0, min(5, (int) round($rating)));
    return str_repeat('★', $filled) . str_repeat('☆', 5 - $filled);
};

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
<main class="container container-wide">
    <div class="modal-actions" style="justify-content:space-between;align-items:center;margin-bottom:0.5rem">
        <h1 style="margin:0">Hotel properties</h1>
        <button type="button" class="primary" data-open-property-modal>Add hotel</button>
    </div>
    <p class="hint">
        Blacklist is yours to set; teammate adverse preferences for the same hotel are shown.
        Use address lookup when adding/editing to fill street + map pins.
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
        <div class="table-scroll">
            <table class="filterable-table properties-table">
                <thead>
                    <tr>
                        <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'hotel_name']), ENT_QUOTES) ?>">Hotel</a></th>
                        <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'brand']), ENT_QUOTES) ?>">Brand</a></th>
                        <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'city']), ENT_QUOTES) ?>">Address</a></th>
                        <th>Office venue</th>
                        <th>Desk</th>
                        <th>Blacklisted by</th>
                        <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'overall_rating']), ENT_QUOTES) ?>">Rating</a></th>
                        <th></th>
                    </tr>
                    <tr class="table-filters">
                        <th>
                            <input type="search" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="Name" aria-label="Filter by hotel name">
                        </th>
                        <th>
                            <select name="brand" aria-label="Filter by brand" onchange="this.form.submit()">
                                <option value="">Any</option>
                                <?php foreach ($brandOptions as $brandName): ?>
                                    <option value="<?= htmlspecialchars($brandName, ENT_QUOTES) ?>"
                                        <?= strcasecmp($filters['brand'], $brandName) === 0 ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($brandName, ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                            <select name="has_desk" aria-label="Filter by desk" onchange="this.form.submit()">
                                <option value="">Any</option>
                                <option value="1" <?= $filters['has_desk'] === '1' ? 'selected' : '' ?>>Yes</option>
                                <option value="0" <?= $filters['has_desk'] === '0' ? 'selected' : '' ?>>No</option>
                            </select>
                        </th>
                        <th>
                            <div class="table-filter-pair">
                                <select name="blacklisted" aria-label="Filter by my blacklist" onchange="this.form.submit()">
                                    <option value="">Mine</option>
                                    <option value="1" <?= $filters['blacklisted'] === '1' ? 'selected' : '' ?>>Yes</option>
                                    <option value="0" <?= $filters['blacklisted'] === '0' ? 'selected' : '' ?>>No</option>
                                </select>
                                <select name="teammate_adverse" aria-label="Filter by teammate blacklist" onchange="this.form.submit()">
                                    <option value="">Anyone</option>
                                    <option value="1" <?= $filters['teammate_adverse'] === '1' ? 'selected' : '' ?>>Teammate</option>
                                </select>
                            </div>
                        </th>
                        <th></th>
                        <th>
                            <button type="submit" class="secondary table-filter-go">Go</button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($properties === []): ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                No properties match.
                                <button type="button" class="primary" data-open-property-modal>Add hotel</button>
                                or <a href="/hotels/add.php">log a stay</a>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($properties as $property): ?>
                            <?php
                            $pid = (int) $property->id;
                            $adverse = $adverseByPropertyId[$pid] ?? [];
                            $blacklisters = [];
                            $blacklistTitles = [];
                            if (isset($myBlacklistIds[$pid])) {
                                $blacklisters[] = 'You';
                                $myReason = $blacklistRepo->reason($user->id, $pid);
                                $blacklistTitles[] = 'You' . ($myReason !== null && $myReason !== '' ? ': ' . $myReason : '');
                            }
                            foreach ($adverse as $a) {
                                $blacklisters[] = $a['display_name'];
                                $blacklistTitles[] = $a['display_name']
                                    . ($a['reason'] !== null && $a['reason'] !== '' ? ': ' . $a['reason'] : '');
                            }
                            $officeVenue = $property->walkToOfficeNotes !== null && trim($property->walkToOfficeNotes) !== ''
                                ? trim($property->walkToOfficeNotes)
                                : null;
                            ?>
                            <tr>
                                <td class="col-hotel"><?= htmlspecialchars($property->hotelName, ENT_QUOTES) ?></td>
                                <td class="col-brand"><?= htmlspecialchars($property->brand ?? '—', ENT_QUOTES) ?></td>
                                <td class="col-address"><?= htmlspecialchars($formatAddress($property), ENT_QUOTES) ?></td>
                                <td class="col-office">
                                    <?= $officeVenue !== null
                                        ? htmlspecialchars($officeVenue, ENT_QUOTES)
                                        : '—' ?>
                                </td>
                                <td class="col-desk">
                                    <?php if ($property->hasDesk): ?>
                                        Yes<?= $property->deskNotes
                                            ? ' <span class="hint" title="' . htmlspecialchars($property->deskNotes, ENT_QUOTES) . '">·</span>'
                                            : '' ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="col-blacklist">
                                    <?php if ($blacklisters === []): ?>
                                        —
                                    <?php else: ?>
                                        <span class="badge badge-blacklist" title="<?= htmlspecialchars(implode('; ', $blacklistTitles), ENT_QUOTES) ?>">
                                            <?= htmlspecialchars(implode(', ', $blacklisters), ENT_QUOTES) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-rating">
                                    <?php if ($property->overallRating !== null): ?>
                                        <span class="star-rating"
                                            title="<?= htmlspecialchars(number_format($property->overallRating, 1) . ' public average', ENT_QUOTES) ?>"
                                            aria-label="<?= htmlspecialchars(number_format($property->overallRating, 1) . ' out of 5', ENT_QUOTES) ?>">
                                            <?= $formatStars($property->overallRating) ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="col-actions">
                                    <a href="/hotels/edit-property.php?id=<?= $pid ?>">Edit</a>
                                    ·
                                    <a href="/hotels/add.php?property_id=<?= $pid ?>">Log stay</a>
                                    <?php if ($property->website !== null && trim($property->website) !== ''): ?>
                                        ·
                                        <a href="<?= htmlspecialchars($property->website, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Website</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
