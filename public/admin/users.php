<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$repo = new UserRepository($app['db'], $app['logger']);

if (!$repo->isAdmin($user)) {
    http_response_code(403);
    echo 'Site admins only.';
    exit;
}

$errors = [];
$message = null;
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $editId > 0 ? $repo->find($editId) : null;

$parseManagerId = static function (): ?int {
    $raw = $_POST['manager_id'] ?? '';
    if ($raw === '' || $raw === null) {
        return null;
    }
    return (int) $raw;
};

$parseDotted = static function (): array {
    $ids = $_POST['dotted_manager_ids'] ?? [];
    if (!is_array($ids)) {
        return [];
    }
    return array_values(array_unique(array_map('intval', $ids)));
};

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
                $managerId = $parseManagerId();
                $created = $repo->create(
                    trim((string) ($_POST['username'] ?? '')),
                    (string) ($_POST['email'] ?? ''),
                    $plain,
                    trim((string) ($_POST['display_name'] ?? '')),
                    'subordinate',
                    $managerId,
                    $user->id,
                    isset($_POST['is_admin']),
                );
                if ($app['db']->tableExists('user_dotted_managers')) {
                    $repo->setDottedManagers((int) $created->id, $parseDotted(), $user->id);
                }
                $message = "Created user {$created->username} (ID {$created->id}).";
            } elseif ($action === 'update' && $editing !== null) {
                $repo->updateProfile(
                    $editing->id,
                    trim((string) ($_POST['display_name'] ?? '')),
                    $parseManagerId(),
                    isset($_POST['is_active']),
                    isset($_POST['is_admin']),
                    $user->id,
                );
                if ($app['db']->tableExists('user_dotted_managers')) {
                    $repo->setDottedManagers($editing->id, $parseDotted(), $user->id);
                }
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
$usersById = [];
foreach ($allUsers as $u) {
    $usersById[$u->id] = $u;
}

$editEmails = ($editing !== null && $app['db']->tableExists('user_emails'))
    ? $repo->emailsForUser($editing->id)
    : [];
$editDottedIds = ($editing !== null && $app['db']->tableExists('user_dotted_managers'))
    ? $repo->dottedManagerIds($editing->id)
    : [];

/** Build a simple solid-line org tree for display. */
$children = [];
foreach ($allUsers as $u) {
    if (!$u->isActive) {
        continue;
    }
    $parent = $u->managerId ?? 0;
    $children[$parent][] = $u;
}
foreach ($children as &$list) {
    usort($list, static fn ($a, $b) => strcasecmp($a->displayName, $b->displayName));
}
unset($list);

$renderTree = null;
$renderTree = static function (int $parentId, int $depth) use (&$renderTree, $children, $repo): void {
    foreach ($children[$parentId] ?? [] as $node) {
        $pad = str_repeat(' ', $depth);
        $dotted = [];
        if ($repo->dottedManagerIds($node->id) !== []) {
            foreach ($repo->dottedManagers($node->id) as $dm) {
                $dotted[] = $dm->displayName;
            }
        }
        echo '<div class="org-row" style="padding-left:' . (0.75 * $depth) . 'rem">';
        echo htmlspecialchars($pad . $node->displayName, ENT_QUOTES);
        echo ' <span class="text-dim">(@' . htmlspecialchars($node->username, ENT_QUOTES) . ')</span>';
        if ($dotted !== []) {
            echo ' <span class="badge badge-status-delay" title="Dotted-line managers">⋯ '
                . htmlspecialchars(implode(', ', $dotted), ENT_QUOTES) . '</span>';
        }
        if ($node->isAdmin) {
            echo ' <span class="badge badge-status-home">admin</span>';
        }
        echo ' <a href="/admin/users.php?id=' . (int) $node->id . '">edit</a>';
        echo '</div>';
        $renderTree($node->id, $depth + 1);
    }
};
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
    <h1>Users &amp; org chart</h1>
    <p>
        Org structure is who reports to whom (solid line), plus optional dotted-line managers.
        Site admins can manage accounts — that is separate from the reporting chart.
    </p>

    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3>Org chart (solid line)</h3>
        <p class="hint">Indentation = reports to the person above. Dotted-line managers show as ⋯ badges.</p>
        <?php
        // Roots: no manager, or manager missing/inactive (orphans still show).
        $roots = $children[0] ?? [];
        $rootIds = [];
        foreach ($roots as $r) {
            $rootIds[$r->id] = true;
        }
        foreach ($allUsers as $u) {
            if (!$u->isActive || $u->managerId === null || isset($rootIds[$u->id])) {
                continue;
            }
            $mgr = $usersById[$u->managerId] ?? null;
            if ($mgr === null || !$mgr->isActive) {
                $roots[] = $u;
                $rootIds[$u->id] = true;
            }
        }
        if ($roots === []) {
            echo '<p class="empty-state">No active users yet.</p>';
        } else {
            foreach ($roots as $root) {
                echo '<div class="org-row">';
                echo '<strong>' . htmlspecialchars($root->displayName, ENT_QUOTES) . '</strong>';
                echo ' <span class="text-dim">(@' . htmlspecialchars($root->username, ENT_QUOTES) . ')</span>';
                $dotted = [];
                foreach ($repo->dottedManagers($root->id) as $dm) {
                    $dotted[] = $dm->displayName;
                }
                if ($dotted !== []) {
                    echo ' <span class="badge badge-status-delay">⋯ ' . htmlspecialchars(implode(', ', $dotted), ENT_QUOTES) . '</span>';
                }
                if ($root->isAdmin) {
                    echo ' <span class="badge badge-status-home">admin</span>';
                }
                echo ' <a href="/admin/users.php?id=' . (int) $root->id . '">edit</a>';
                echo '</div>';
                $renderTree($root->id, 1);
            }
        }
        ?>
    </div>

    <div class="card">
        <h3>Directory</h3>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Reports to</th>
                    <th>Dotted line</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allUsers as $u): ?>
                <?php
                $mgr = $u->managerId !== null ? ($usersById[$u->managerId] ?? null) : null;
                $dottedNames = array_map(
                    static fn ($d) => $d->displayName,
                    $repo->dottedManagers($u->id)
                );
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($u->displayName, ENT_QUOTES) ?>
                        <?php if ($u->isAdmin): ?><span class="badge badge-status-home">admin</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u->username, ENT_QUOTES) ?></td>
                    <td><?= $mgr !== null ? htmlspecialchars($mgr->displayName, ENT_QUOTES) : '—' ?></td>
                    <td><?= $dottedNames !== [] ? htmlspecialchars(implode(', ', $dottedNames), ENT_QUOTES) : '—' ?></td>
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
            <label>Reports to (solid line)
                <select name="manager_id">
                    <option value="">— none (org root) —</option>
                    <?php foreach ($allUsers as $u): ?>
                        <?php if ($u->id === $editing->id) {
                            continue;
                        } ?>
                        <option value="<?= (int) $u->id ?>" <?= $editing->managerId === $u->id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u->displayName . ' (@' . $u->username . ')', ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <fieldset>
                <legend>Dotted-line managers</legend>
                <p class="hint">Matrix / secondary reporting. Does not replace the solid-line manager above.</p>
                <div class="checkbox-grid">
                    <?php foreach ($allUsers as $u): ?>
                        <?php if ($u->id === $editing->id || !$u->isActive) {
                            continue;
                        } ?>
                        <label>
                            <input type="checkbox" name="dotted_manager_ids[]" value="<?= (int) $u->id ?>"
                                <?= in_array($u->id, $editDottedIds, true) ? 'checked' : '' ?>
                                <?= $editing->managerId === $u->id ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($u->displayName, ENT_QUOTES) ?>
                            <?php if ($editing->managerId === $u->id): ?>
                                <span class="text-dim">(solid line)</span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <label>
                <input type="checkbox" name="is_active" value="1" <?= $editing->isActive ? 'checked' : '' ?>>
                Active
            </label>
            <label>
                <input type="checkbox" name="is_admin" value="1" <?= $editing->isAdmin ? 'checked' : '' ?>>
                Site admin (Users / Site settings)
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
            <label>Reports to (solid line)
                <select name="manager_id">
                    <option value="">— none (org root) —</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= (int) $u->id ?>"><?= htmlspecialchars($u->displayName, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <fieldset>
                <legend>Dotted-line managers (optional)</legend>
                <div class="checkbox-grid">
                    <?php foreach ($allUsers as $u): ?>
                        <?php if (!$u->isActive) {
                            continue;
                        } ?>
                        <label>
                            <input type="checkbox" name="dotted_manager_ids[]" value="<?= (int) $u->id ?>">
                            <?= htmlspecialchars($u->displayName, ENT_QUOTES) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <label>
                <input type="checkbox" name="is_admin" value="1">
                Site admin
            </label>
            <button type="submit" class="primary">Create user</button>
        </form>
    </div>
</main>
</body>
</html>
