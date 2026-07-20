<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$repo = new UserRepository($app['db'], $app['logger']);

$errors = [];
$message = null;
$schemaWarning = null;

if (!$app['db']->tableExists('user_emails')) {
    $schemaWarning = 'Database is missing user_emails. On the server run: php scripts/migrate.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaWarning === null) {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'add') {
                $email = (string) ($_POST['email'] ?? '');
                $label = trim((string) ($_POST['label'] ?? ''));
                $repo->addEmail($user->id, $email, $label !== '' ? $label : null, $user->id);
                $message = 'Email address added. Forward confirmations from that mailbox to the travel dump address.';
            } elseif ($action === 'remove') {
                $repo->removeEmail($user->id, (int) ($_POST['email_id'] ?? 0), $user->id);
                $message = 'Email address removed.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$emails = $schemaWarning === null ? $repo->emailsForUser($user->id) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; My emails</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>My email addresses</h1>
    <p>
        When you forward a hotel or airline confirmation into the travel mailbox,
        NexWAYPOINT matches the message <code>From:</code> to one of these addresses
        and attaches the booking to your account. Add every address you send from
        (work, personal, phone mail app, etc.).
    </p>

    <?php if ($schemaWarning !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($schemaWarning, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3>Addresses on this account</h3>
        <?php if ($emails === []): ?>
            <p class="empty-state">No addresses yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Label</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($emails as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['email'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['label'] ?? ($row['is_primary'] ? 'Primary' : ''), ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($row['is_primary']): ?>
                                <span class="text-dim">primary</span>
                            <?php elseif ($row['id'] > 0): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="email_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="danger">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($schemaWarning === null): ?>
    <div class="card">
        <h3>Add another address</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="add">
            <label>Email
                <input type="email" name="email" required placeholder="you@work.com">
            </label>
            <label>Label (optional)
                <input type="text" name="label" maxlength="100" placeholder="Work / Personal / Phone">
            </label>
            <button type="submit" class="primary">Add email</button>
        </form>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
