<?php

declare(strict_types=1);

/**
 * Shared top nav. Expects $user (User) in scope. Optional $unreadCount (int);
 * if omitted and $app is available, unread travel alerts are loaded.
 */

use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\AirportRepository;
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
        $navStatusEngine = new TripStatusEngine(
            new TripRepository($app['db'], $app['logger']),
            $app['logger'],
            new AirportRepository($app['db'], $app['logger']),
        );
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

if (!function_exists('nexwaypoint_initials')) {
    function nexwaypoint_initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= strtoupper(substr($part, 0, 1));
            if (strlen($letters) >= 2) {
                break;
            }
        }
        return $letters !== '' ? $letters : '?';
    }
}

$navInitials = nexwaypoint_initials($user->displayName);
$navAlertLabel = $unreadCount > 0
    ? ($unreadCount > 9 ? '9+' : (string) $unreadCount) . ' unread alerts'
    : 'Account menu';
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
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
        <div class="nav-dropdown nav-account">
            <button type="button"
                class="nav-account-trigger"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="<?= htmlspecialchars($navAlertLabel, ENT_QUOTES) ?>"
                title="<?= htmlspecialchars($user->displayName, ENT_QUOTES) ?>">
                <?php if ($user->hasPhoto()): ?>
                    <img class="avatar-circle avatar-sm"
                        src="/media/avatar.php?id=<?= (int) $user->id ?>"
                        alt=""
                        style="object-position: <?= (float) $user->photoFocusX ?>% <?= (float) $user->photoFocusY ?>%;">
                <?php else: ?>
                    <span class="avatar-circle avatar-sm avatar-fallback"><?= htmlspecialchars($navInitials, ENT_QUOTES) ?></span>
                <?php endif; ?>
                <?php if ($unreadCount > 0): ?>
                    <span class="nav-account-badge"><?= $unreadCount > 9 ? '9+' : (int) $unreadCount ?></span>
                <?php endif; ?>
            </button>
            <div class="nav-dropdown-menu nav-account-menu" role="menu">
                <a role="menuitem" href="/alerts/index.php">
                    Alerts
                    <?php if ($unreadCount > 0): ?>
                        <span class="nav-account-menu-count"><?= (int) $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a role="menuitem" href="/receipts/index.php">Receipts</a>
                <a role="menuitem" href="/hotels/add.php">Log stay</a>
                <a role="menuitem" href="/trips/builder.php">Add trip</a>
                <span class="nav-dropdown-sep" aria-hidden="true"></span>
                <a role="menuitem" href="/settings/profile.php">My profile</a>
                <a role="menuitem" href="/settings/index.php">Overview</a>
                <a role="menuitem" href="/settings/emails.php">My emails</a>
                <a role="menuitem" href="/settings/visibility.php">Sharing</a>
                <a role="menuitem" href="/settings/calendars.php">Calendar feeds</a>
                <?php if ($navIsAdmin): ?>
                    <span class="nav-dropdown-sep" aria-hidden="true"></span>
                    <a role="menuitem" href="/settings/site.php">Site catalogs</a>
                    <a role="menuitem" href="/settings/appearance.php">Appearance</a>
                    <a role="menuitem" href="/settings/integrations.php">Integrations</a>
                    <a role="menuitem" href="/settings/jobs.php">Cron / service status</a>
                    <a role="menuitem" href="/settings/users.php">Users</a>
                <?php endif; ?>
                <?php if ($user->isSystem): ?>
                    <span class="nav-dropdown-sep" aria-hidden="true"></span>
                    <a role="menuitem" href="/settings/mail-review.php">Mail review</a>
                <?php endif; ?>
                <span class="nav-dropdown-sep" aria-hidden="true"></span>
                <a role="menuitem" href="/logout.php">Sign out</a>
            </div>
        </div>
    </div>
</nav>
<?php if (isset($statusFlash) && is_array($statusFlash) && ($statusFlash['type'] ?? '') === 'success'): ?>
    <div class="container status-flash-banner">
        <p class="alert alert-success"><?= htmlspecialchars((string) $statusFlash['text'], ENT_QUOTES) ?></p>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_status_override_modal.php'; ?>
