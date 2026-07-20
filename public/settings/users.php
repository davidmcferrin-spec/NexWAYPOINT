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

$settingsSection = 'users';

$errors = [];
$message = null;
$editId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $editId = (int) $_POST['user_id'];
} elseif (isset($_GET['id'])) {
    $editId = (int) $_GET['id'];
}
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
                $editing = null;
                $editId = 0;
            } elseif ($action === 'update') {
                if ($editing === null) {
                    throw new InvalidArgumentException('User not found.');
                }
                $repo->updateProfile(
                    $editing->id,
                    trim((string) ($_POST['display_name'] ?? '')),
                    $editing->isSystem ? null : $parseManagerId(),
                    isset($_POST['is_active']),
                    $editing->isSystem ? true : isset($_POST['is_admin']),
                    $user->id,
                );
                if (!$editing->isSystem && $app['db']->tableExists('user_dotted_managers')) {
                    $repo->setDottedManagers($editing->id, $parseDotted(), $user->id);
                }
                $message = 'User updated.';
                $editing = $repo->find($editing->id);
            } elseif ($action === 'reset_password') {
                if ($editing === null) {
                    throw new InvalidArgumentException('User not found.');
                }
                $plain = (string) ($_POST['password'] ?? '');
                $repo->updatePassword($editing->id, $plain, $user->id);
                $message = 'Password updated.';
            } elseif ($action === 'update_primary_email') {
                if ($editing === null) {
                    throw new InvalidArgumentException('User not found.');
                }
                $repo->updatePrimaryEmail($editing->id, (string) ($_POST['email'] ?? ''), $user->id);
                $message = 'Primary email updated.';
                $editing = $repo->find($editing->id);
            } elseif ($action === 'add_email') {
                if ($editing === null) {
                    throw new InvalidArgumentException('User not found.');
                }
                $label = trim((string) ($_POST['label'] ?? ''));
                $repo->addEmail(
                    $editing->id,
                    (string) ($_POST['email'] ?? ''),
                    $label !== '' ? $label : null,
                    $user->id,
                );
                $message = 'Email alias added.';
            } elseif ($action === 'remove_email') {
                if ($editing === null) {
                    throw new InvalidArgumentException('User not found.');
                }
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
$orgUsers = array_values(array_filter($allUsers, static fn ($u) => !$u->isSystem));
$systemUsers = array_values(array_filter($allUsers, static fn ($u) => $u->isSystem));

$editEmails = ($editing !== null && $app['db']->tableExists('user_emails'))
    ? $repo->emailsForUser($editing->id)
    : [];
$editDottedIds = ($editing !== null && !$editing->isSystem && $app['db']->tableExists('user_dotted_managers'))
    ? $repo->dottedManagerIds($editing->id)
    : [];

/** Build a simple solid-line org tree for display (system accounts excluded). */
$children = [];
foreach ($orgUsers as $u) {
    if (!$u->isActive) {
        continue;
    }
    $parent = $u->managerId ?? 0;
    // Don't hang org members under a system account if one was wrongly linked.
    if ($parent > 0 && isset($usersById[$parent]) && $usersById[$parent]->isSystem) {
        $parent = 0;
    }
    $children[$parent][] = $u;
}
foreach ($children as &$list) {
    usort($list, static fn ($a, $b) => strcasecmp($a->displayName, $b->displayName));
}
unset($list);

$renderOrgNode = null;
$renderOrgNode = static function ($node, int $depth = 0) use (&$renderOrgNode, $children, $repo): void {
    $dotted = [];
    foreach ($repo->dottedManagers($node->id) as $dm) {
        if ($dm->isSystem) {
            continue;
        }
        $dotted[] = $dm->displayName;
    }
    $parts = preg_split('/\s+/', trim($node->displayName)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    if ($initials === '') {
        $initials = '?';
    }
    $kids = $children[$node->id] ?? [];
    ?>
    <li class="org-node<?= $kids !== [] ? ' has-children' : '' ?>">
        <div class="org-person">
            <span class="org-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES) ?></span>
            <div class="org-person-body">
                <div class="org-person-name">
                    <?= htmlspecialchars($node->displayName, ENT_QUOTES) ?>
                    <?php if ($node->isAdmin): ?>
                        <span class="badge badge-status-home">admin</span>
                    <?php endif; ?>
                </div>
                <div class="org-person-meta">@<?= htmlspecialchars($node->username, ENT_QUOTES) ?></div>
                <?php if ($dotted !== []): ?>
                    <div class="org-person-dotted" title="Dotted-line managers">
                        Dotted: <?= htmlspecialchars(implode(', ', $dotted), ENT_QUOTES) ?>
                    </div>
                <?php endif; ?>
            </div>
            <a class="org-person-edit" href="/settings/users.php?id=<?= (int) $node->id ?>">Manage</a>
        </div>
        <?php if ($kids !== []): ?>
            <ul class="org-branch">
                <?php foreach ($kids as $child): ?>
                    <?php $renderOrgNode($child, $depth + 1); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </li>
    <?php
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
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>Users &amp; org chart</h1>
    <p>
        Org structure is who reports to whom (solid line), plus optional dotted-line managers.
        The seeded system admin is isolated — not on the chart. Site-admin for real people is a separate flag.
    </p>

    <?php foreach ($errors as $err): ?>
        <?php if ($editing !== null) {
            continue;
        } ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null && $editing === null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3>Org chart</h3>
        <p class="hint">Solid-line reporting. Dotted-line managers appear under each person when set.</p>
        <?php
        // Roots: no manager, or manager missing/inactive (orphans still show).
        $roots = $children[0] ?? [];
        $rootIds = [];
        foreach ($roots as $r) {
            $rootIds[$r->id] = true;
        }
        foreach ($orgUsers as $u) {
            if (!$u->isActive || $u->managerId === null || isset($rootIds[$u->id])) {
                continue;
            }
            $mgr = $usersById[$u->managerId] ?? null;
            if ($mgr === null || !$mgr->isActive || $mgr->isSystem) {
                $roots[] = $u;
                $rootIds[$u->id] = true;
            }
        }
        if ($roots === []) {
            echo '<p class="empty-state">No org members yet (system admin is excluded).</p>';
        } else {
            echo '<ul class="org-tree">';
            foreach ($roots as $root) {
                $renderOrgNode($root);
            }
            echo '</ul>';
        }
        ?>
    </div>

    <?php if ($systemUsers !== []): ?>
    <div class="card">
        <h3>System accounts</h3>
        <p class="hint">Isolated from the org chart — site bootstrap only.</p>
        <ul>
            <?php foreach ($systemUsers as $su): ?>
                <li>
                    <?= htmlspecialchars($su->displayName, ENT_QUOTES) ?>
                    <span class="text-dim">(@<?= htmlspecialchars($su->username, ENT_QUOTES) ?>)</span>
                    <span class="badge badge-status-home">system</span>
                    <a href="/settings/users.php?id=<?= (int) $su->id ?>">Manage</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

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
                $mgr = (!$u->isSystem && $u->managerId !== null) ? ($usersById[$u->managerId] ?? null) : null;
                $dottedNames = $u->isSystem ? [] : array_map(
                    static fn ($d) => $d->displayName,
                    array_filter($repo->dottedManagers($u->id), static fn ($d) => !$d->isSystem)
                );
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($u->displayName, ENT_QUOTES) ?>
                        <?php if ($u->isSystem): ?><span class="badge badge-status-home">system</span>
                        <?php elseif ($u->isAdmin): ?><span class="badge badge-status-home">admin</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u->username, ENT_QUOTES) ?></td>
                    <td><?= $u->isSystem ? '— (isolated)' : ($mgr !== null ? htmlspecialchars($mgr->displayName, ENT_QUOTES) : '—') ?></td>
                    <td><?= $dottedNames !== [] ? htmlspecialchars(implode(', ', $dottedNames), ENT_QUOTES) : '—' ?></td>
                    <td><?= $u->isActive ? 'yes' : 'no' ?></td>
                    <td><a href="/settings/users.php?id=<?= (int) $u->id ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($editing !== null): ?>
<div id="user-edit-modal" class="modal-backdrop" <?= $errors !== [] || $message !== null || isset($_GET['id']) ? '' : 'hidden' ?>
     data-auto-open="1">
    <div class="modal-panel" role="dialog" aria-labelledby="user-edit-modal-title" style="max-width: 40rem; max-height: 90vh; overflow: auto;">
        <h2 id="user-edit-modal-title">Edit <?= htmlspecialchars($editing->displayName, ENT_QUOTES) ?></h2>
        <?php foreach ($errors as $err): ?>
            <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
        <?php endforeach; ?>
        <?php if ($message !== null): ?>
            <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <?php if ($editing->isSystem): ?>
            <p class="hint">System account — isolated from the org chart. Reporting lines do not apply.</p>
        <?php endif; ?>

        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" value="<?= (int) $editing->id ?>">
            <label>Display name
                <input type="text" name="display_name" required value="<?= htmlspecialchars($editing->displayName, ENT_QUOTES) ?>">
            </label>
            <?php if (!$editing->isSystem): ?>
            <label>Reports to (solid line)
                <select name="manager_id">
                    <option value="">— none (org root) —</option>
                    <?php foreach ($orgUsers as $u): ?>
                        <?php if ($u->id === $editing->id || !$u->isActive) {
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
                    <?php foreach ($orgUsers as $u): ?>
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
            <?php endif; ?>
            <label>
                <input type="checkbox" name="is_active" value="1" <?= $editing->isActive ? 'checked' : '' ?>>
                Active
            </label>
            <?php if ($editing->isSystem): ?>
                <p class="hint">Site admin is always on for the system account.</p>
            <?php else: ?>
            <label>
                <input type="checkbox" name="is_admin" value="1" <?= $editing->isAdmin ? 'checked' : '' ?>>
                Site admin (Settings → Users / Site catalogs)
            </label>
            <?php endif; ?>
            <div class="modal-actions">
                <button type="submit" class="primary">Save user</button>
            </div>
        </form>

        <hr style="border: none; border-top: 1px solid var(--border); margin: 1.25rem 0;">

        <h3>Primary email</h3>
        <p class="hint">Used for account identity and mail-import matching.</p>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update_primary_email">
            <input type="hidden" name="user_id" value="<?= (int) $editing->id ?>">
            <label>Primary email
                <input type="email" name="email" required value="<?= htmlspecialchars($editing->email, ENT_QUOTES) ?>">
            </label>
            <button type="submit" class="primary">Update primary email</button>
        </form>

        <h3 style="margin-top: 1.25rem;">Forward-from aliases</h3>
        <p class="hint">Additional addresses that map mail to this account.</p>
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
                                    <input type="hidden" name="user_id" value="<?= (int) $editing->id ?>">
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
            <input type="hidden" name="user_id" value="<?= (int) $editing->id ?>">
            <label>Add alias
                <input type="email" name="email" required>
            </label>
            <label>Label
                <input type="text" name="label" maxlength="100">
            </label>
            <button type="submit" class="primary">Add email</button>
        </form>

        <h3 style="margin-top: 1.25rem;">Reset password</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" value="<?= (int) $editing->id ?>">
            <label>New password (min 12)
                <input type="password" name="password" required minlength="12" autocomplete="new-password">
            </label>
            <button type="submit" class="primary">Set password</button>
        </form>

        <div class="modal-actions" style="margin-top: 1.25rem;">
            <a href="/settings/users.php">Close</a>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('user-edit-modal');
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    modal.addEventListener('click', function (ev) {
        if (ev.target === modal) {
            window.location.href = '/settings/users.php';
        }
    });
});
</script>
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
                    <?php foreach ($orgUsers as $u): ?>
                        <?php if (!$u->isActive) {
                            continue;
                        } ?>
                        <option value="<?= (int) $u->id ?>"><?= htmlspecialchars($u->displayName, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <fieldset>
                <legend>Dotted-line managers (optional)</legend>
                <div class="checkbox-grid">
                    <?php foreach ($orgUsers as $u): ?>
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
