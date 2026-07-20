<?php

declare(strict_types=1);

use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$isAdmin = (new UserRepository($app['db'], $app['logger']))->isAdmin($user);
$settingsSection = 'hub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Settings</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>Settings</h1>
    <p>Account preferences and, for site admins, shared catalogs and the org chart.</p>

    <div class="settings-grid">
        <a class="settings-card" href="/settings/emails.php">
            <h3>My emails</h3>
            <p>Forward-from addresses used to match confirmation mail to your account.</p>
        </a>
        <a class="settings-card" href="/settings/visibility.php">
            <h3>Sharing</h3>
            <p>What teammates can see about your travel by reporting relationship.</p>
        </a>
        <?php if ($isAdmin): ?>
            <a class="settings-card" href="/settings/site.php">
                <h3>Site catalogs</h3>
                <p>Airline carriers, rail operators, offices/venues, and hotel brands.</p>
            </a>
            <a class="settings-card" href="/settings/integrations.php">
                <h3>Integrations</h3>
                <p>IMAP mail credentials and FlightAware AeroAPI key / rate limits.</p>
            </a>
            <a class="settings-card" href="/settings/jobs.php">
                <h3>Cron / service status</h3>
                <p>Last cron run status for mail polling and flight enrichment (counts only).</p>
            </a>
            <a class="settings-card" href="/settings/users.php">
                <h3>Users</h3>
                <p>Accounts, org chart, solid- and dotted-line reporting.</p>
            </a>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
