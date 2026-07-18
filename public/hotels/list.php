<?php

declare(strict_types=1);

use NexWaypont\Hotels\HotelStayRepository;
use NexWaypont\Hotels\CriteriaSuggestionEngine;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$repo = new HotelStayRepository($app['db'], $app['logger']);
$stays = $repo->findForUser($user->id);

$engine = new CriteriaSuggestionEngine();
$noteThemes = $engine->analyzeNotesForRecurringThemes($stays);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexWAYPONT &middot; Hotel Stays</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPONT</a></div>
    <div>
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/list.php">Hotels</a>
        <a href="/hotels/add.php">+ Log a stay</a>
        <a href="/logout.php">Sign out (<?= htmlspecialchars($user->displayName, ENT_QUOTES) ?>)</a>
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
                    <th>City</th>
                    <th>Dates</th>
                    <th>Room</th>
                    <th>Rating</th>
                    <th>Price</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stays as $stay): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($stay->hotelName, ENT_QUOTES) ?>
                            <?php if ($stay->isBlacklisted): ?>
                                <span class="badge badge-blacklist">Blacklisted</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($stay->city ?? '—', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($stay->stayStart, ENT_QUOTES) ?> &rarr; <?= htmlspecialchars($stay->stayEnd, ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($stay->roomNumber ?? '—', ENT_QUOTES) ?></td>
                        <td><?= $stay->rating !== null ? str_repeat('★', $stay->rating) . str_repeat('☆', 5 - $stay->rating) : '—' ?></td>
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
