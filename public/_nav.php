<?php

declare(strict_types=1);

/**
 * Shared top nav. Expects $user (User) in scope. Optional $unreadCount (int).
 */

use NexWaypoint\Users\User;

/** @var User $user */
$unreadCount = $unreadCount ?? 0;
?>
<nav class="navbar">
    <div class="navbar-brand"><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div class="navbar-links">
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/properties.php">Hotels</a>
        <a href="/hotels/map.php">Map</a>
        <span class="navbar-sep" aria-hidden="true"></span>
        <a href="/hotels/add.php">Log stay</a>
        <a href="/flights/add.php">Add flight</a>
        <a href="/trains/add.php">Add train</a>
        <span class="navbar-sep" aria-hidden="true"></span>
        <a href="/settings/index.php">Settings</a>
        <a class="navbar-signout" href="/logout.php"><?= htmlspecialchars($user->displayName, ENT_QUOTES) ?><?php if ($unreadCount > 0): ?> · <?= (int) $unreadCount ?> new<?php endif; ?> · Sign out</a>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
    </div>
</nav>
