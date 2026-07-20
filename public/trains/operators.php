<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Trips\Carrier;
use NexWaypoint\Trips\CarrierRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$repo = new CarrierRepository($app['db'], $app['logger']);
$errors = [];
$message = null;
$schemaWarning = null;
$type = Carrier::TYPE_RAIL;

if (!$app['db']->tableExists('carriers')) {
    $schemaWarning = 'Database is missing the carriers table. On the server run: php scripts/migrate.php';
}

$editId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
$editing = null;
if ($schemaWarning === null && $editId > 0) {
    try {
        $editing = $repo->find($editId);
    } catch (Throwable $e) {
        $schemaWarning = 'Could not load operators. Run: php scripts/migrate.php';
    }
    if ($editing !== null && ($editing->userId !== $user->id || $editing->carrierType !== $type)) {
        $editing = null;
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($schemaWarning !== null) {
        $errors[] = $schemaWarning;
    } elseif (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        try {
            $name = trim((string) ($_POST['name'] ?? ''));
            $iata = strtoupper(trim((string) ($_POST['iata_code'] ?? '')));
            $id = (int) ($_POST['id'] ?? 0);

            if ($id > 0) {
                $existing = $repo->find($id);
                if ($existing === null || $existing->userId !== $user->id || $existing->carrierType !== $type) {
                    throw new InvalidArgumentException('Operator not found.');
                }
                $editing = $repo->update(new Carrier(
                    id: $id,
                    userId: $user->id,
                    name: $name,
                    iataCode: $iata !== '' ? $iata : null,
                    carrierType: $type,
                ), $user->id);
                $message = 'Operator updated.';
                $editId = (int) $editing->id;
            } else {
                $repo->create(new Carrier(
                    id: null,
                    userId: $user->id,
                    name: $name,
                    iataCode: $iata !== '' ? $iata : null,
                    carrierType: $type,
                ), $user->id);
                $message = 'Operator created.';
                $editing = null;
                $editId = 0;
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$operators = [];
if ($schemaWarning === null) {
    try {
        $operators = $repo->findForUser($user->id, $type);
    } catch (Throwable $e) {
        $schemaWarning = 'Could not load operators. Run: php scripts/migrate.php';
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Rail operators</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Rail operators</h1>
    <p class="hint">Operators used when logging train segments (e.g. Amtrak). Airline carriers stay under <a href="/flights/carriers.php">Flights</a>.</p>

    <?php if ($schemaWarning !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($schemaWarning, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3><?= $editing !== null ? 'Edit operator' : 'Add operator' ?></h3>
        <form class="stack" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="id" value="<?= (int) $editId ?>">
            <label>Operator name
                <input type="text" name="name" required <?= $schemaWarning !== null ? 'disabled' : '' ?>
                    value="<?= htmlspecialchars($editing->name ?? (string) ($_POST['name'] ?? ''), ENT_QUOTES) ?>"
                    placeholder="Amtrak">
            </label>
            <label>IATA / code (optional)
                <input type="text" name="iata_code" maxlength="3" <?= $schemaWarning !== null ? 'disabled' : '' ?>
                    value="<?= htmlspecialchars($editing->iataCode ?? (string) ($_POST['iata_code'] ?? ''), ENT_QUOTES) ?>"
                    style="text-transform:uppercase" placeholder="2V">
            </label>
            <div class="modal-actions">
                <button type="submit" class="primary" <?= $schemaWarning !== null ? 'disabled' : '' ?>><?= $editing !== null ? 'Save changes' : 'Add operator' ?></button>
                <?php if ($editing !== null): ?>
                    <a class="secondary" href="/trains/operators.php" style="display:inline-block;padding:0.6rem 1.2rem;text-decoration:none;border:1px solid var(--border);border-radius:4px;">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($operators === []): ?>
        <p class="empty-state">No rail operators yet. Add Amtrak above or from the train form.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Name</th><th>Code</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($operators as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c->name, ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($c->iataCode ?? '—', ENT_QUOTES) ?></td>
                        <td><a href="/trains/operators.php?id=<?= (int) $c->id ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
