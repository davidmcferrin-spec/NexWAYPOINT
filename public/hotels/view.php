<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$repo = new HotelStayRepository($app['db'], $app['logger'], $propertyRepo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stay = $repo->find($id);

if ($stay === null || $stay->userId !== $user->id) {
    http_response_code(404);
    echo 'Stay not found.';
    exit;
}

$property = $propertyRepo->find($stay->hotelPropertyId);
if ($property === null) {
    http_response_code(404);
    echo 'Property not found.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $repo->delete($stay->id, $user->id);
        header('Location: /hotels/list.php');
        exit;
    }
}

$photos = $repo->photosFor($stay->id);

$bedLabels = ['king' => 'King', 'queen' => 'Queen', 'dual_queen' => 'Dual queen'];
$bathLabels = ['tub' => 'Tub', 'walk_in_shower' => 'Walk-in shower'];

$rows = [
    'Brand' => $property->brand,
    'Phone' => $property->phone,
    'Address' => trim(implode(', ', array_filter([
        $property->addressLine1,
        $property->addressLine2,
        $property->city,
        $property->stateRegion,
        $property->postalCode,
        $property->country,
    ]))) ?: null,
    'Overall property rating' => $property->overallRating !== null
        ? number_format($property->overallRating, 1) . '★ (average of stay ratings)'
        : null,
    'Room number' => $stay->roomNumber,
    'Bed type' => $stay->bedType !== null ? ($bedLabels[$stay->bedType] ?? $stay->bedType) : null,
    'Bathroom' => $stay->bathroomType !== null ? ($bathLabels[$stay->bathroomType] ?? $stay->bathroomType) : null,
    'Dates' => $stay->stayStart . ' to ' . $stay->stayEnd,
    'This stay rating' => $stay->stayRating !== null
        ? str_repeat('★', $stay->stayRating) . str_repeat('☆', 5 - $stay->stayRating)
        : null,
    'WiFi quality' => $property->wifiQuality,
    'Noise level' => $property->noiseLevel,
    'Walk to office/venue' => $property->walkToOffice
        ? ('Yes' . ($property->walkToOfficeNotes ? ' — ' . $property->walkToOfficeNotes : ''))
        : null,
    'Destination charge' => $property->hasDestinationFee ? 'Yes' : null,
    'Last stay price' => $stay->lastStayPrice !== null
        ? "{$stay->currency} " . number_format($stay->lastStayPrice, 2)
        : null,
    'Booking source' => $stay->bookingSource,
    'Confirmation code' => $stay->confirmationCode,
    'Would return' => $stay->wouldReturn === null ? null : ($stay->wouldReturn ? 'Yes' : 'No'),
];

$amenities = array_filter([
    $property->hasDesk ? 'Desk' : null,
    $property->hasPool ? 'Pool' : null,
    $property->hasHotTub ? 'Hot tub' : null,
    $property->hasBreakfast ? 'Breakfast' : null,
    $property->hasGym ? 'On-site gym' : null,
    $property->hasOffsiteGym ? 'Off-site gym' : null,
    $property->hasFreeParking ? 'Free parking' : null,
    $property->hasAirportShuttle ? 'Airport shuttle' : null,
    $property->hasEvCharging ? 'EV charging' : null,
    $property->hasOnsiteRestaurant ? 'On-site restaurant' : null,
    $property->walkToOffice ? 'Walk to office' : null,
    $property->hasDestinationFee ? 'Destination charge' : null,
]);

$teammateAdverse = $propertyRepo->findTeammateAdversePreferences($user->id, $property->hotelName, $property->city);
$locationAdverse = [];
if ($property->city !== null && trim($property->city) !== '') {
    $locationAdverse = $propertyRepo->findTeammateAdverseAtLocation(
        $user->id,
        $property->city,
        $property->stateRegion
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; <?= htmlspecialchars($property->hotelName, ENT_QUOTES) ?></title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <p><a href="/hotels/properties.php">&larr; Back to hotels</a></p>
    <h1>
        <?= htmlspecialchars($property->hotelName, ENT_QUOTES) ?>
        <?php if ($property->isBlacklisted): ?><span class="badge badge-blacklist">My blacklist</span><?php endif; ?>
        <?php if ($stay->isPrivate): ?><span class="badge badge-blacklist">Private</span><?php endif; ?>
    </h1>

    <?php if ($property->isBlacklisted): ?>
        <p class="alert alert-error"><?= htmlspecialchars($property->blacklistReason ?? 'No reason recorded.', ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if ($teammateAdverse !== []): ?>
        <div class="card">
            <h3>Teammate adverse preference (same hotel)</h3>
            <ul>
                <?php foreach ($teammateAdverse as $a): ?>
                    <li>
                        <strong><?= htmlspecialchars($a['display_name'], ENT_QUOTES) ?></strong>
                        blacklisted this property
                        <?= $a['reason'] ? ': ' . htmlspecialchars($a['reason'], ENT_QUOTES) : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php
    $otherLocation = array_values(array_filter(
        $locationAdverse,
        static fn (array $a) => !in_array($a['property_id'], array_column($teammateAdverse, 'property_id'), true)
    ));
    ?>
    <?php if ($otherLocation !== []): ?>
        <div class="card">
            <h3>Teammate adverse preferences nearby (same city)</h3>
            <ul>
                <?php foreach ($otherLocation as $a): ?>
                    <li>
                        <strong><?= htmlspecialchars($a['display_name'], ENT_QUOTES) ?></strong>
                        — <?= htmlspecialchars($a['hotel_name'], ENT_QUOTES) ?>
                        <?= $a['reason'] ? ': ' . htmlspecialchars($a['reason'], ENT_QUOTES) : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p>
        <a href="/hotels/add.php?property_id=<?= (int) $property->id ?>">Log another stay at this property</a>
        &middot;
        <a href="/hotels/edit-property.php?id=<?= (int) $property->id ?>">Edit property</a>
    </p>

    <div class="card">
        <table>
            <?php foreach ($rows as $label => $value): ?>
                <?php if ($value !== null && $value !== ''): ?>
                    <tr><th><?= htmlspecialchars((string) $label, ENT_QUOTES) ?></th><td><?= htmlspecialchars((string) $value, ENT_QUOTES) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($amenities !== []): ?>
                <tr><th>Amenities</th><td><?= htmlspecialchars(implode(', ', $amenities), ENT_QUOTES) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($property->uniqueFeatures !== null): ?>
        <div class="card"><h3>Unique features</h3><p><?= nl2br(htmlspecialchars($property->uniqueFeatures, ENT_QUOTES)) ?></p></div>
    <?php endif; ?>

    <?php if ($stay->notes !== null): ?>
        <div class="card"><h3>Stay notes</h3><p><?= nl2br(htmlspecialchars($stay->notes, ENT_QUOTES)) ?></p></div>
    <?php endif; ?>

    <?php if ($photos !== []): ?>
        <div class="card">
            <h3>Photos</h3>
            <?php foreach ($photos as $photo): ?>
                <p><?= htmlspecialchars($photo['caption'] ?? basename((string) $photo['file_path']), ENT_QUOTES) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" onsubmit="return confirm('Delete this stay? This cannot be undone.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="danger">Delete stay</button>
    </form>
</main>
</body>
</html>
