<?php

declare(strict_types=1);

/**
 * Shared top nav. Expects $user (User) in scope. Optional $unreadCount (int);
 * if omitted and $app is available, unread travel alerts are loaded.
 */

use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
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

$statusFlash = null;
if (isset($_SESSION['nexwaypoint_status_flash']) && is_array($_SESSION['nexwaypoint_status_flash'])) {
    $statusFlash = $_SESSION['nexwaypoint_status_flash'];
    unset($_SESSION['nexwaypoint_status_flash']);
}

$navStatusLabel = 'Home';
$navStatusCode = 'home';
if (isset($app) && is_array($app) && isset($app['db'], $app['logger'])) {
    try {
        $navStatusEngine = new TripStatusEngine(new TripRepository($app['db'], $app['logger']), $app['logger']);
        $navResolved = $navStatusEngine->resolveForUser($user->id);
        $navStatusLabel = (string) $navResolved['label'];
        $navStatusCode = (string) $navResolved['status'];
    } catch (Throwable) {
        // Keep defaults if status engine unavailable.
    }
}

if (!function_exists('nexwaypoint_status_badge_class')) {
    function nexwaypoint_status_badge_class(string $status): string
    {
        return match ($status) {
            'home', 'office' => 'badge-status-home',
            'remote' => 'badge-status-travel',
            'delayed', 'cancelled' => 'badge-status-delay',
            'pre_flight', 'en_route', 'post_flight', 'layover', 'at_hotel' => 'badge-status-travel',
            default => 'badge-status-travel',
        };
    }
}
?>
<nav class="navbar">
    <div class="navbar-brand"><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div class="navbar-status">
        <span class="navbar-status-prefix">You are:</span>
        <button type="button"
            class="navbar-status-trigger badge <?= htmlspecialchars(nexwaypoint_status_badge_class($navStatusCode), ENT_QUOTES) ?>"
            data-open-modal="status-override-modal"
            title="Set a temporary status override">
            <?= htmlspecialchars($navStatusLabel, ENT_QUOTES) ?>
        </button>
    </div>
    <div class="navbar-links">
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/trips/list.php">Trips</a>
        <a href="/hotels/properties.php">Hotels</a>
        <a href="/hotels/map.php">Map</a>
        <span class="navbar-sep" aria-hidden="true"></span>
        <a href="/hotels/add.php">Log stay</a>
        <a href="/trips/builder.php">Add trip</a>
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
<?php if (isset($statusFlash) && is_array($statusFlash) && ($statusFlash['type'] ?? '') === 'success'): ?>
    <div class="container status-flash-banner">
        <p class="alert alert-success"><?= htmlspecialchars((string) $statusFlash['text'], ENT_QUOTES) ?></p>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_status_override_modal.php'; ?>
