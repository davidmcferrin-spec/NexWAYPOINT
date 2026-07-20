<?php

declare(strict_types=1);

use NexWaypoint\Hotels\HotelPropertyRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);

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

$properties = $propertyRepo->searchForUser($user->id, $filters, $sort);
$locations = $propertyRepo->locationsForUser($user->id);

$adverseByPropertyId = [];
foreach ($properties as $property) {
    $adverseByPropertyId[(int) $property->id] = $propertyRepo->findTeammateAdversePreferences(
        $user->id,
        $property->hotelName,
        $property->city
    );
}

$queryBase = static function (array $overrides = []) use ($filters, $sort): string {
    $params = array_merge($filters, ['sort' => $sort], $overrides);
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return '/hotels/properties.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Hotel Properties</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Hotel properties</h1>
    <p class="hint">
        Your properties — filter and sort below.
        Blacklist is yours alone to set; teammate adverse preferences for the same hotel name/city are shown so you can avoid problem locations.
        <a href="/hotels/list.php">View stays</a>
        ·
        <a href="/hotels/map.php">Map view</a>
    </p>

    <form class="card stack" method="get" action="/hotels/properties.php" style="max-width:none">
        <div class="checkbox-grid">
            <label>Search
                <input type="text" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="Name or brand">
            </label>
            <label>City
                <input type="text" name="city" value="<?= htmlspecialchars($filters['city'], ENT_QUOTES) ?>" list="city-suggestions">
                <datalist id="city-suggestions">
                    <?php
                    $cities = [];
                    foreach ($locations as $loc) {
                        $cities[$loc['city']] = true;
                    }
                    foreach (array_keys($cities) as $c):
                        ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>">
                    <?php endforeach; ?>
                </datalist>
            </label>
            <label>State / region
                <input type="text" name="state_region" value="<?= htmlspecialchars($filters['state_region'], ENT_QUOTES) ?>">
            </label>
            <label>Destination charge
                <select name="destination_fee">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['destination_fee'] === '1' ? 'selected' : '' ?>>Has charge</option>
                    <option value="0" <?= $filters['destination_fee'] === '0' ? 'selected' : '' ?>>No charge</option>
                </select>
            </label>
            <label>My blacklist
                <select name="blacklisted">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['blacklisted'] === '1' ? 'selected' : '' ?>>Blacklisted</option>
                    <option value="0" <?= $filters['blacklisted'] === '0' ? 'selected' : '' ?>>Not blacklisted</option>
                </select>
            </label>
            <label>Teammate adverse
                <select name="teammate_adverse">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['teammate_adverse'] === '1' ? 'selected' : '' ?>>Has teammate blacklist</option>
                </select>
            </label>
            <label>Sort
                <select name="sort">
                    <option value="hotel_name" <?= $sort === 'hotel_name' ? 'selected' : '' ?>>Name</option>
                    <option value="city" <?= $sort === 'city' ? 'selected' : '' ?>>City</option>
                    <option value="overall_rating" <?= $sort === 'overall_rating' ? 'selected' : '' ?>>Overall rating</option>
                    <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>Recently updated</option>
                </select>
            </label>
        </div>
        <div class="modal-actions">
            <button type="submit" class="primary">Apply</button>
            <a class="secondary" href="/hotels/properties.php" style="display:inline-block;padding:0.6rem 1.2rem;text-decoration:none;border:1px solid var(--border);border-radius:4px;">Clear</a>
        </div>
    </form>

    <?php if ($properties === []): ?>
        <p class="empty-state">No properties match. <a href="/hotels/add.php">Log a stay</a> to add one.</p>
    <?php else: ?>
        <p class="hint"><?= count($properties) ?> propert<?= count($properties) === 1 ? 'y' : 'ies' ?></p>
        <table>
            <thead>
                <tr>
                    <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'hotel_name']), ENT_QUOTES) ?>">Hotel</a></th>
                    <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'city']), ENT_QUOTES) ?>">Location</a></th>
                    <th><a href="<?= htmlspecialchars($queryBase(['sort' => 'overall_rating']), ENT_QUOTES) ?>">Overall</a></th>
                    <th>Destination Charge</th>
                    <th>Flags</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
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
                            <?php if ($property->hasDestinationFee): ?>
                                Yes
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($property->isBlacklisted): ?>
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
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
