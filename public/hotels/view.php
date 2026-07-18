<?php

declare(strict_types=1);

use NexWaypont\Core\Csrf;
use NexWaypont\Hotels\HotelStayRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$repo = new HotelStayRepository($app['db'], $app['logger']);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stay = $repo->find($id);

if ($stay === null || $stay->userId !== $user->id) {
    http_response_code(404);
    echo 'Stay not found.';
    exit;
}

$deleted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $repo->delete($stay->id, $user->id);
        header('Location: /hotels/list.php');
        exit;
    }
}

$photos = $repo->photosFor($stay->id);

$rows = [
    'Brand' => $stay->brand,
    'Address' => trim(implode(', ', array_filter([$stay->addressLine1, $stay->addressLine2, $stay->city, $stay->stateRegion, $stay->postalCode, $stay->country]))) ?: null,
    'Room number' => $stay->roomNumber,
    'Dates' => $stay->stayStart . ' to ' . $stay->stayEnd,
    'Rating' => $stay->rating !== null ? str_repeat('★', $stay->rating) . str_repeat('☆', 5 - $stay->rating) : null,
    'WiFi quality' => $stay->wifiQuality,
    'Noise level' => $stay->noiseLevel,
    'Last stay price' => $stay->lastStayPrice !== null ? "{$stay->currency} " . number_format($stay->lastStayPrice, 2) : null,
    'Booking source' => $stay->bookingSource,
    'Confirmation code' => $stay->confirmationCode,
    'Would return' => $stay->wouldReturn === null ? null : ($stay->wouldReturn ? 'Yes' : 'No'),
];

$amenities = array_filter([
    $stay->hasDesk ? 'Desk' : null,
    $stay->hasPool ? 'Pool' : null,
    $stay->hasHotTub ? 'Hot tub' : null,
    $stay->hasBreakfast ? 'Breakfast' : null,
    $stay->hasGym ? 'Gym' : null,
    $stay->hasFreeParking ? 'Free parking' : null,
    $stay->hasAirportShuttle ? 'Airport shuttle' : null,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexWAYPONT &middot; <?= htmlspecialchars($stay->hotelName, ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPONT</a></div>
    <div>
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/list.php">Hotels</a>
        <a href="/hotels/add.php">+ Log a stay</a>
        <a href="/logout.php">Sign out</a>
    </div>
</nav>
<main class="container">
    <p><a href="/hotels/list.php">&larr; Back to hotels</a></p>
    <h1>
        <?= htmlspecialchars($stay->hotelName, ENT_QUOTES) ?>
        <?php if ($stay->isBlacklisted): ?><span class="badge badge-blacklist">Blacklisted</span><?php endif; ?>
    </h1>

    <?php if ($stay->isBlacklisted): ?>
        <p class="alert alert-error"><?= htmlspecialchars($stay->blacklistReason ?? 'No reason recorded.', ENT_QUOTES) ?></p>
    <?php endif; ?>

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

    <?php if ($stay->uniqueFeatures !== null): ?>
        <div class="card"><h3>Unique features</h3><p><?= nl2br(htmlspecialchars($stay->uniqueFeatures, ENT_QUOTES)) ?></p></div>
    <?php endif; ?>

    <?php if ($stay->notes !== null): ?>
        <div class="card"><h3>Notes</h3><p><?= nl2br(htmlspecialchars($stay->notes, ENT_QUOTES)) ?></p></div>
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
        <button type="submit" class="primary" style="background:#f87171;">Delete stay</button>
    </form>
</main>
</body>
</html>
