<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$repo = new UserRepository($app['db'], $app['logger']);

if ($user->role !== 'manager') {
    http_response_code(403);
    echo 'Managers only.';
    exit;
}

$errors = [];
$message = null;
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $editId > 0 ? $repo->find($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $plain = (string) ($_POST['password'] ?? '');
                if (strlen($plain) < 12) {
                    throw new InvalidArgumentException('Password must be at least 12 characters.');
                }
                $managerId = ($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null;
                $created = $repo->create(
                    trim((string) ($_POST['username'] ?? '')),
                    (string) ($_POST['email'] ?? ''),
                    $plain,
                    trim((string) ($_POST['display_name'] ?? '')),
                    (string) ($_POST['role'] ?? 'subordinate'),
                    $managerId,
                    $user->id,
                );
                $message = "Created user {$created->username} (ID {$created->id}).";
            } elseif ($action === 'update' && $editing !== null) {
                $managerId = ($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null;
                $repo->updateProfile(
                    $editing->id,
                    trim((string) ($_POST['display_name'] ?? '')),
                    (string) ($_POST['role'] ?? $editing->role),
                    $managerId,
                    isset($_POST['is_active']),
                    $user->id,
                );
                $message = 'User updated.';
                $editing = $repo->find($editing->id);
            } elseif ($action === 'reset_password' && $editing !== null) {
                $plain = (string) ($_POST['password'] ?? '');
                $repo->updatePassword($editing->id, $plain, $user->id);
                $message = 'Password updated.';
            } elseif ($action === 'add_email' && $editing !== null) {
                $label = trim((string) ($_POST['label'] ?? ''));
                $repo->addEmail(
                    $editing->id,
                    (string) ($_POST['email'] ?? ''),
                    $label !== '' ? $label : null,
                    $user->id,
                );
                $message = 'Email alias added.';
            } elseif ($action === 'remove_email' && $editing !== null) {
                $repo->removeEmail($editing->id, (int) ($_POST['email_id'] ?? 0), $user->id);
                $message = 'Email alias removed.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$allUsers = $repo->findAll();
$editEmails = ($editing !== null && $app['db']->tableExists('user_emails'))
    ? $repo->emailsForUser($editing->id)
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Users</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Users</h1>
    <p>Managers can create accounts, set roles, and attach forward-from email aliases used by mail import.</p>

    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3>Directory</h3>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Primary email</th>
                    <th>Role</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allUsers as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u->displayName, ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($u->username, ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($u->email, ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($u->role, ENT_QUOTES) ?></td>
                    <td><?= $u->isActive ? 'yes' : 'no' ?></td>
                    <td><a href="/admin/users.php?id=<?= (int) $u->id ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($editing !== null): ?>
    <div class="card">
        <h3>Edit <?= htmlspecialchars($editing->displayName, ENT_QUOTES) ?></h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update">
            <label>Display name
                <input type="text" name="display_name" required value="<?= htmlspecialchars($editing->displayName, ENT_QUOTES) ?>">
            </label>
            <label>Role
                <select name="role">
                    <?php foreach (['manager', 'peer', 'subordinate'] as $role): ?>
                        <option value="<?= $role ?>" <?= $editing->role === $role ? 'selected' : '' ?>><?= $role ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Manager
                <select name="manager_id">
                    <option value="">— none —</option>
                    <?php foreach ($allUsers as $u): ?>
                        <?php if ($u->id === $editing->id) {
                            continue;
                        } ?>
                        <option value="<?= (int) $u->id ?>" <?= $editing->managerId === $u->id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u->displayName . ' (' . $u->username . ')', ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <input type="checkbox" name="is_active" value="1" <?= $editing->isActive ? 'checked' : '' ?>>
                Active
            </label>
            <button type="submit" class="primary">Save user</button>
            <a href="/admin/users.php">Close</a>
        </form>
    </div>

    <div class="card">
        <h3>Forward-from emails</h3>
        <p>Mail import matches <code>From:</code> against these addresses.</p>
        <?php if ($editEmails === []): ?>
            <p class="empty-state">No aliases (run migrate if table is missing).</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Email</th><th>Label</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($editEmails as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['email'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['label'] ?? '', ENT_QUOTES) ?></td>
                        <td>
                            <?php if (!$row['is_primary'] && $row['id'] > 0): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                    <input type="hidden" name="action" value="remove_email">
                                    <input type="hidden" name="email_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="danger">Remove</button>
                                </form>
                            <?php else: ?>
                                <span class="text-dim">primary</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" class="stack" style="margin-top:1rem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="add_email">
            <label>Add email
                <input type="email" name="email" required>
            </label>
            <label>Label
                <input type="text" name="label" maxlength="100">
            </label>
            <button type="submit" class="primary">Add email</button>
        </form>
    </div>

    <div class="card">
        <h3>Reset password</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="reset_password">
            <label>New password (min 12)
                <input type="password" name="password" required minlength="12" autocomplete="new-password">
            </label>
            <button type="submit" class="primary">Set password</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Create user</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="create">
            <label>Username
                <input type="text" name="username" required autocomplete="off">
            </label>
            <label>Display name
                <input type="text" name="display_name" required>
            </label>
            <label>Primary email
                <input type="email" name="email" required>
            </label>
            <label>Password (min 12)
                <input type="password" name="password" required minlength="12" autocomplete="new-password">
            </label>
            <label>Role
                <select name="role">
                    <option value="subordinate">subordinate</option>
                    <option value="peer">peer</option>
                    <option value="manager">manager</option>
                </select>
            </label>
            <label>Manager
                <select name="manager_id">
                    <option value="">— none —</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= (int) $u->id ?>"><?= htmlspecialchars($u->displayName, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="primary">Create user</button>
        </form>
    </div>
</main>
</body>
</html>
