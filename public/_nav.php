<?php

declare(strict_types=1);

/**
 * Shared top nav. Expects $user (User) in scope. Optional $unreadCount (int).
 */

use NexWaypoint\Users\User;

/** @var User $user */
$unreadCount = $unreadCount ?? 0;
// is_admin is the site-admin gate; legacy role=manager still allowed pre-migrate.
$isAdmin = $user->isAdmin || $user->role === 'manager';
?>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div class="navbar-links">
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/properties.php">Hotels</a>
        <a href="/hotels/list.php">Stays</a>
        <a href="/hotels/map.php">Hotel map</a>
        <a href="/hotels/add.php">+ Log a stay</a>
        <a href="/flights/add.php">+ Add a flight</a>
        <a href="/trains/add.php">+ Add a train</a>
        <a href="/flights/carriers.php">Carriers</a>
        <a href="/trains/operators.php">Rail operators</a>
        <a href="/settings/emails.php">My emails</a>
        <a href="/settings/visibility.php">Sharing</a>
        <?php if ($isAdmin): ?>
            <a href="/settings/site.php">Site settings</a>
            <a href="/admin/users.php">Users</a>
        <?php endif; ?>
        <a href="/logout.php">Sign out (<?= htmlspecialchars($user->displayName, ENT_QUOTES) ?>)<?php if ($unreadCount > 0): ?> &middot; <?= (int) $unreadCount ?> new<?php endif; ?></a>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
    </div>
</nav>
