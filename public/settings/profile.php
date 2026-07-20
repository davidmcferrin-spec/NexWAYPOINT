<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$repo = new UserRepository($app['db'], $app['logger']);

$errors = [];
$message = null;
$settingsSection = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'update_profile') {
                $displayName = trim((string) ($_POST['display_name'] ?? ''));
                if ($displayName === '') {
                    throw new InvalidArgumentException('Display name is required.');
                }
                $user = $repo->updateProfile(
                    $user->id,
                    $displayName,
                    $user->managerId,
                    $user->isActive,
                    $user->isAdmin,
                    $user->id,
                );
                $message = 'Profile updated.';
            } elseif ($action === 'change_password') {
                $plain = (string) ($_POST['password'] ?? '');
                $confirm = (string) ($_POST['password_confirm'] ?? '');
                if ($plain !== $confirm) {
                    throw new InvalidArgumentException('Passwords do not match.');
                }
                $repo->updatePassword($user->id, $plain, $user->id);
                $message = 'Password updated.';
            } else {
                throw new InvalidArgumentException('Unknown action.');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; My profile</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>My profile</h1>
    <p class="hint">Account name and password. Email addresses used for mail import are under <a href="/settings/emails.php">My emails</a>.</p>

    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3>Account</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update_profile">
            <label>Username
                <input type="text" value="<?= htmlspecialchars($user->username, ENT_QUOTES) ?>" disabled>
            </label>
            <p class="hint">Username cannot be changed here.</p>
            <label>Display name
                <input type="text" name="display_name" required maxlength="120"
                    value="<?= htmlspecialchars($user->displayName, ENT_QUOTES) ?>">
            </label>
            <label>Primary email
                <input type="email" value="<?= htmlspecialchars($user->email, ENT_QUOTES) ?>" disabled>
            </label>
            <p class="hint"><a href="/settings/emails.php">Manage primary and alias emails</a></p>
            <button type="submit" class="primary">Save profile</button>
        </form>
    </div>

    <div class="card">
        <h3>Change password</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="change_password">
            <label>New password (min 12)
                <input type="password" name="password" required minlength="12" autocomplete="new-password">
            </label>
            <label>Confirm password
                <input type="password" name="password_confirm" required minlength="12" autocomplete="new-password">
            </label>
            <button type="submit" class="primary">Update password</button>
        </form>
    </div>
</main>
</body>
</html>
