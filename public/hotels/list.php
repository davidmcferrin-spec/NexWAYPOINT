<?php

declare(strict_types=1);

use NexWaypoint\Hotels\CriteriaSuggestionEngine;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$stayRepo = new HotelStayRepository($app['db'], $app['logger'], $propertyRepo);
$stays = $stayRepo->findForUser($user->id);
$propertiesById = [];
foreach ($propertyRepo->findForUser($user->id) as $property) {
    $propertiesById[$property->id] = $property;
}

$engine = new CriteriaSuggestionEngine();
$noteThemes = $engine->analyzeNotesForRecurringThemes($stays);

$bedLabels = ['king' => 'King', 'queen' => 'Queen', 'dual_queen' => 'Dual queen'];
$bathLabels = ['tub' => 'Tub', 'walk_in_shower' => 'Walk-in shower'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Hotel Stays</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div class="navbar-links">
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/properties.php">Hotels</a>
        <a href="/hotels/list.php">Stays</a>
        <a href="/hotels/add.php">+ Log a stay</a>
        <a href="/flights/add.php">+ Add a flight</a>
        <a href="/logout.php">Sign out (<?= htmlspecialchars($user->displayName, ENT_QUOTES) ?>)</a>
        <?php require dirname(__DIR__) . '/_theme_toggle.php'; ?>
    </div>
</nav>
<main class="container">
    <h1>Hotel Stays</h1>

    <?php if ($noteThemes !== []): ?>
        <div class="card">
            <h3>Patterns in your notes</h3>
            <ul>
                <?php foreach ($noteThemes as $t): ?>
                    <li><?= htmlspecialchars($t['suggestion'], ENT_QUOTES) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($stays === []): ?>
        <p class="empty-state">No stays logged yet. <a href="/hotels/add.php">Log your first stay</a>.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Hotel</th>
                    <th>Overall</th>
                    <th>City</th>
                    <th>Dates</th>
                    <th>Room</th>
                    <th>Stay rating</th>
                    <th>Price</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stays as $stay): ?>
                    <?php $property = $propertiesById[$stay->hotelPropertyId] ?? null; ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($property->hotelName ?? 'Unknown', ENT_QUOTES) ?>
                            <?php if ($property?->isBlacklisted): ?>
                                <span class="badge badge-blacklist">Blacklisted</span>
                            <?php endif; ?>
                            <?php if ($stay->isPrivate): ?>
                                <span class="badge badge-blacklist">Private</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($property?->overallRating !== null): ?>
                                <?= number_format($property->overallRating, 1) ?>★
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($property->city ?? '—', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($stay->stayStart, ENT_QUOTES) ?> &rarr; <?= htmlspecialchars($stay->stayEnd, ENT_QUOTES) ?></td>
                        <td>
                            <?= htmlspecialchars($stay->roomNumber ?? '—', ENT_QUOTES) ?>
                            <?php if ($stay->bedType !== null): ?>
                                · <?= htmlspecialchars($bedLabels[$stay->bedType] ?? $stay->bedType, ENT_QUOTES) ?>
                            <?php endif; ?>
                            <?php if ($stay->bathroomType !== null): ?>
                                · <?= htmlspecialchars($bathLabels[$stay->bathroomType] ?? $stay->bathroomType, ENT_QUOTES) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $stay->stayRating !== null ? str_repeat('★', $stay->stayRating) . str_repeat('☆', 5 - $stay->stayRating) : '—' ?></td>
                        <td><?= $stay->lastStayPrice !== null ? htmlspecialchars($stay->currency, ENT_QUOTES) . ' ' . number_format($stay->lastStayPrice, 2) : '—' ?></td>
                        <td><a href="/hotels/view.php?id=<?= $stay->id ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
