<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelBrandRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

if ($user->role !== 'manager') {
    http_response_code(403);
    echo 'Managers only.';
    exit;
}

$repo = new HotelBrandRepository($app['db'], $app['logger']);
$errors = [];
$message = null;
$schemaWarning = null;

if (!$repo->tableReady()) {
    $schemaWarning = 'Database is missing hotel_brands. On the server run: php scripts/migrate.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaWarning === null) {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'add') {
                $created = $repo->create((string) ($_POST['name'] ?? ''), $user->id);
                $message = "Added brand {$created->name}.";
            } elseif ($action === 'remove') {
                $repo->delete((int) ($_POST['brand_id'] ?? 0), $user->id);
                $message = 'Brand removed from the dropdown (existing properties keep their stored brand).';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$brands = $schemaWarning === null ? $repo->findActive() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Site settings</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Site settings</h1>
    <p>Shared options used across the site (not per-user).</p>

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
        <h3>Hotel brands</h3>
        <p>These appear in the Brand dropdown when adding or editing a hotel property. Seeded defaults: Marriott, Hilton, IHG, Hyatt, Choice Hotels.</p>

        <?php if ($brands === []): ?>
            <p class="empty-state">No active brands.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Brand</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($brands as $brand): ?>
                    <tr>
                        <td><?= htmlspecialchars($brand->name, ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($brand->id !== null): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="brand_id" value="<?= (int) $brand->id ?>">
                                <button type="submit" class="danger">Remove</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($schemaWarning === null): ?>
        <form method="post" class="stack" style="margin-top:1rem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="add">
            <label>Add brand
                <input type="text" name="name" required maxlength="100" placeholder="e.g. Wyndham">
            </label>
            <button type="submit" class="primary">Add brand</button>
        </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
