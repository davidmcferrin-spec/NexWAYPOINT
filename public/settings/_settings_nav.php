<?php

declare(strict_types=1);

/**
 * Settings area subnav. Expects $user (User). Optional $settingsSection (string).
 */

use NexWaypoint\Users\User;

/** @var User $user */
$settingsSection = $settingsSection ?? '';
$settingsIsAdmin = $user->isAdmin || $user->role === 'manager';

$settingsLinkClass = static function (string $section) use ($settingsSection): string {
    return $settingsSection === $section ? 'settings-nav-link is-active' : 'settings-nav-link';
};
?>
<nav class="settings-nav" aria-label="Settings">
    <a class="<?= $settingsLinkClass('hub') ?>" href="/settings/index.php">Overview</a>
    <a class="<?= $settingsLinkClass('emails') ?>" href="/settings/emails.php">My emails</a>
    <a class="<?= $settingsLinkClass('sharing') ?>" href="/settings/visibility.php">Sharing</a>
    <?php if ($settingsIsAdmin): ?>
        <span class="settings-nav-sep" aria-hidden="true"></span>
        <a class="<?= $settingsLinkClass('site') ?>" href="/settings/site.php">Site catalogs</a>
        <a class="<?= $settingsLinkClass('jobs') ?>" href="/settings/jobs.php">Cron / service status</a>
        <a class="<?= $settingsLinkClass('users') ?>" href="/settings/users.php">Users</a>
    <?php endif; ?>
</nav>
