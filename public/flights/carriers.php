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

$editId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
$editing = $editId > 0 ? $repo->find($editId) : null;
if ($editing !== null && $editing->userId !== $user->id) {
    $editing = null;
    $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        try {
            $name = trim((string) ($_POST['name'] ?? ''));
            $iata = strtoupper(trim((string) ($_POST['iata_code'] ?? '')));
            $id = (int) ($_POST['id'] ?? 0);

            if ($id > 0) {
                $existing = $repo->find($id);
                if ($existing === null || $existing->userId !== $user->id) {
                    throw new InvalidArgumentException('Carrier not found.');
                }
                $editing = $repo->update(new Carrier(
                    id: $id,
                    userId: $user->id,
                    name: $name,
                    iataCode: $iata !== '' ? $iata : null,
                ), $user->id);
                $message = 'Carrier updated.';
                $editId = (int) $editing->id;
            } else {
                $created = $repo->create(new Carrier(
                    id: null,
                    userId: $user->id,
                    name: $name,
                    iataCode: $iata !== '' ? $iata : null,
                ), $user->id);
                $message = 'Carrier created.';
                $editing = null;
                $editId = 0;
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$carriers = $repo->findForUser($user->id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Carriers</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div class="navbar-links">
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/properties.php">Hotels</a>
        <a href="/flights/add.php">+ Add a flight</a>
        <a href="/flights/carriers.php">Carriers</a>
        <a href="/logout.php">Sign out</a>
        <?php require dirname(__DIR__) . '/_theme_toggle.php'; ?>
    </div>
</nav>
<main class="container">
    <h1>Airline carriers</h1>
    <p class="hint">Each carrier stores an IATA code so flight entry only needs the flight number.</p>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3><?= $editing !== null ? 'Edit carrier' : 'Add carrier' ?></h3>
        <form class="stack" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="id" value="<?= (int) $editId ?>">
            <label>Airline name
                <input type="text" name="name" required
                    value="<?= htmlspecialchars($editing->name ?? (string) ($_POST['name'] ?? ''), ENT_QUOTES) ?>">
            </label>
            <label>IATA code
                <input type="text" name="iata_code" required maxlength="3"
                    value="<?= htmlspecialchars($editing->iataCode ?? (string) ($_POST['iata_code'] ?? ''), ENT_QUOTES) ?>"
                    style="text-transform:uppercase">
            </label>
            <div class="modal-actions">
                <button type="submit" class="primary"><?= $editing !== null ? 'Save changes' : 'Add carrier' ?></button>
                <?php if ($editing !== null): ?>
                    <a class="secondary" href="/flights/carriers.php" style="display:inline-block;padding:0.6rem 1.2rem;text-decoration:none;border:1px solid var(--border);border-radius:4px;">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($carriers === []): ?>
        <p class="empty-state">No carriers yet. Add one above or from the flight form.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Name</th><th>IATA</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($carriers as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c->name, ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($c->iataCode ?? '—', ENT_QUOTES) ?></td>
                        <td><a href="/flights/carriers.php?id=<?= (int) $c->id ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
