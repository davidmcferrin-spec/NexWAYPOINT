<?php

declare(strict_types=1);

/** @var array{logger: \NexWaypont\Core\Logger, db: \NexWaypont\Core\Database, users: \NexWaypont\Users\UserRepository, auth: \NexWaypont\Core\Auth} $app */
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
    <title>NexWAYPONT &middot; Sign in</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="auth-page">
<main class="auth-box">
    <h1>NexWAYPONT</h1>
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
</main>
</body>
</html>
