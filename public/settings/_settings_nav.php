<?php

declare(strict_types=1);

/**
 * Settings area subnav. Expects $user (User). Optional $settingsSection (string).
 */

use NexWaypoint\Users\User;

/** @var User $user */
$settingsSection = $settingsSection ?? '';
$settingsIsAdmin = $user->isAdmin || $user->role === 'manager';
$settingsIsSystem = $user->isSystem;

$settingsLinkClass = static function (string $section) use ($settingsSection): string {
    return $settingsSection === $section ? 'settings-nav-link is-active' : 'settings-nav-link';
};
?>
<nav class="settings-nav" aria-label="Settings">
    <a class="<?= $settingsLinkClass('profile') ?>" href="/settings/profile.php">My profile</a>
    <a class="<?= $settingsLinkClass('hub') ?>" href="/settings/index.php">Overview</a>
    <a class="<?= $settingsLinkClass('emails') ?>" href="/settings/emails.php">My emails</a>
    <a class="<?= $settingsLinkClass('sharing') ?>" href="/settings/visibility.php">Sharing</a>
    <?php if ($settingsIsAdmin): ?>
        <span class="settings-nav-sep" aria-hidden="true"></span>
        <a class="<?= $settingsLinkClass('site') ?>" href="/settings/site.php">Site catalogs</a>
        <a class="<?= $settingsLinkClass('appearance') ?>" href="/settings/appearance.php">Appearance</a>
        <a class="<?= $settingsLinkClass('integrations') ?>" href="/settings/integrations.php">Integrations</a>
        <a class="<?= $settingsLinkClass('jobs') ?>" href="/settings/jobs.php">Cron / service status</a>
        <a class="<?= $settingsLinkClass('users') ?>" href="/settings/users.php">Users</a>
    <?php endif; ?>
    <?php if ($settingsIsSystem): ?>
        <span class="settings-nav-sep" aria-hidden="true"></span>
        <a class="<?= $settingsLinkClass('mail-review') ?>" href="/settings/mail-review.php">Mail review</a>
    <?php endif; ?>
</nav>
