<?php

declare(strict_types=1);

/** @var array{logger: \NexWaypoint\Core\Logger, db: \NexWaypoint\Core\Database, users: \NexWaypoint\Users\UserRepository, auth: \NexWaypoint\Core\Auth} $app */
$app = require dirname(__DIR__) . '/config/bootstrap.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $user = $app['auth']->attempt($username, $password);
    if ($user !== null) {
        $app['logger']->info('Login success', ['user_id' => $user->id]);
        header('Location: /dashboard/index.php');
        exit;
    }

    $app['logger']->warning('Login failed', ['username' => $username]);
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Sign in</title>
    <?php require __DIR__ . '/_head_assets.php'; ?>
</head>
<body class="auth-page">
<main class="auth-box">
    <h1>NexWAYPOINT</h1>
    <?php if ($error !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post" action="/login.php">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Sign in</button>
    </form>
    <div class="theme-toggle-wrap">
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
    </div>
</main>
</body>
</html>
