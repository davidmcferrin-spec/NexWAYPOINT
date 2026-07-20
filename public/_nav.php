<?php

declare(strict_types=1);

/**
 * Shared top nav. Expects $user (User) in scope. Optional $unreadCount (int);
 * if omitted and $app is available, unread travel alerts are loaded.
 */

use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Users\User;

/** @var User $user */
if (!isset($unreadCount) && isset($app) && is_array($app) && isset($app['db'])) {
    try {
        $unreadCount = (new NotificationRepository($app['db']))->unreadCount($user->id);
    } catch (Throwable) {
        $unreadCount = 0;
    }
}
$unreadCount = (int) ($unreadCount ?? 0);
$navIsAdmin = $user->isAdmin || $user->role === 'manager';
?>
<nav class="navbar">
    <div class="navbar-brand"><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div class="navbar-links">
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/trips/list.php">Trips</a>
        <a href="/hotels/properties.php">Hotels</a>
        <a href="/hotels/map.php">Map</a>
        <span class="navbar-sep" aria-hidden="true"></span>
        <a href="/hotels/add.php">Log stay</a>
        <a href="/flights/add.php">Add flight</a>
        <a href="/trains/add.php">Add train</a>
        <span class="navbar-sep" aria-hidden="true"></span>
        <div class="nav-dropdown">
            <a href="/settings/index.php" class="nav-dropdown-trigger" aria-haspopup="true" aria-expanded="false">Settings</a>
            <div class="nav-dropdown-menu" role="menu">
                <a role="menuitem" href="/settings/profile.php">My profile</a>
                <a role="menuitem" href="/settings/index.php">Overview</a>
                <a role="menuitem" href="/settings/emails.php">My emails</a>
                <a role="menuitem" href="/settings/visibility.php">Sharing</a>
                <?php if ($navIsAdmin): ?>
                    <span class="nav-dropdown-sep" aria-hidden="true"></span>
                    <a role="menuitem" href="/settings/site.php">Site catalogs</a>
                    <a role="menuitem" href="/settings/appearance.php">Appearance</a>
                    <a role="menuitem" href="/settings/integrations.php">Integrations</a>
                    <a role="menuitem" href="/settings/jobs.php">Cron / service status</a>
                    <a role="menuitem" href="/settings/users.php">Users</a>
                <?php endif; ?>
            </div>
        </div>
        <span class="navbar-account">
            <a class="navbar-profile" href="/settings/profile.php" title="My profile"><?= htmlspecialchars($user->displayName, ENT_QUOTES) ?></a>
            <span class="navbar-account-sep" aria-hidden="true">·</span>
            <a class="navbar-alerts<?= $unreadCount > 0 ? ' has-unread' : '' ?>"
                href="/alerts/index.php"
                title="Travel alerts from email imports and flight status">
                <?php if ($unreadCount > 0): ?>
                    <?= $unreadCount ?> alert<?= $unreadCount === 1 ? '' : 's' ?>
                <?php else: ?>
                    Alerts
                <?php endif; ?>
            </a>
            <span class="navbar-account-sep" aria-hidden="true">·</span>
            <a class="navbar-signout" href="/logout.php">Sign out</a>
        </span>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
    </div>
</nav>
