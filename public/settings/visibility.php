<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$db = $app['db'];

$userRepo = new UserRepository($db, $app['logger']);
$ruleRepo = new VisibilityRuleRepository($db);
$engine = new VisibilityEngine($userRepo, $ruleRepo);

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
    $direction = (string) ($_POST['direction'] ?? '');
    $targetUserId = isset($_POST['target_user_id']) && $_POST['target_user_id'] !== '' ? (int) $_POST['target_user_id'] : null;

    if (in_array($direction, [VisibilityEngine::DIRECTION_TOP_DOWN, VisibilityEngine::DIRECTION_BOTTOM_UP, VisibilityEngine::DIRECTION_LATERAL, VisibilityEngine::DIRECTION_USER_USER], true)) {
        foreach (VisibilityEngine::ALL_FIELDS as $field) {
            $visible = isset($_POST['field'][$field]) && $_POST['field'][$field] === '1';
            $ruleRepo->upsert($user->id, $targetUserId, $direction, $field, $visible, $user->id);
        }
        $message = 'Sharing rules updated.';
    }
}

$myRules = $ruleRepo->findForSubject($user->id);
$otherUsers = array_filter($userRepo->findAllActive(), static fn ($u) => $u->id !== $user->id);

/**
 * Index existing rules for pre-checking the form: [direction][targetUserId or 'default'][field] = bool
 */
$ruleIndex = [];
foreach ($myRules as $rule) {
    $key = $rule->targetUserId ?? 'default';
    $ruleIndex[$rule->direction][$key][$rule->fieldName] = $rule->visible;
}

function isChecked(array $ruleIndex, string $direction, string $key, string $field, bool $default): bool
{
    return $ruleIndex[$direction][$key][$field] ?? $default;
}

$fieldLabels = [
    'destination_city' => 'Destination city',
    'travel_dates' => 'Travel dates',
    'flight_number' => 'Flight #',
    'carrier' => 'Carrier',
    'hotel_name' => 'Hotel name',
    'hotel_address' => 'Hotel address',
    'trip_purpose' => 'Trip purpose',
    'notes' => 'Notes',
];

$panels = [
    VisibilityEngine::DIRECTION_TOP_DOWN => [
        'title' => 'What my manager sees of me (top-down)',
        'default' => VisibilityEngine::ALL_FIELDS,
    ],
    VisibilityEngine::DIRECTION_BOTTOM_UP => [
        'title' => 'What my subordinates see of me (bottom-up)',
        'default' => ['destination_city', 'travel_dates'],
    ],
    VisibilityEngine::DIRECTION_LATERAL => [
        'title' => 'What my peers see of me (lateral)',
        'default' => VisibilityEngine::ALL_FIELDS,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexWAYPOINT &middot; Sharing Settings</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="navbar">
    <div><a href="/dashboard/index.php">NexWAYPOINT</a></div>
    <div>
        <a href="/dashboard/index.php">Dashboard</a>
        <a href="/hotels/list.php">Hotels</a>
        <a href="/hotels/add.php">+ Log a stay</a>
        <a href="/flights/add.php">+ Add a flight</a>
        <a href="/settings/visibility.php">Sharing</a>
        <a href="/logout.php">Sign out</a>
    </div>
</nav>
<main class="container">
    <h1>Sharing Settings</h1>
    <?php if ($message !== null): ?><p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p><?php endif; ?>

    <?php foreach ($panels as $direction => $panel): ?>
        <div class="card">
            <h3><?= htmlspecialchars($panel['title'], ENT_QUOTES) ?></h3>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="direction" value="<?= htmlspecialchars($direction, ENT_QUOTES) ?>">
                <div class="checkbox-grid">
                    <?php foreach ($fieldLabels as $field => $label): ?>
                        <label>
                            <input type="checkbox" name="field[<?= $field ?>]" value="1"
                                <?= isChecked($ruleIndex, $direction, 'default', $field, in_array($field, $panel['default'], true)) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="primary">Save default</button>
            </form>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <h3>Per-person overrides</h3>
        <p>Overrides always win over the direction defaults above, regardless of org relationship.</p>
        <?php foreach ($otherUsers as $other): ?>
            <form method="post" style="margin-bottom:1rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="direction" value="<?= VisibilityEngine::DIRECTION_USER_USER ?>">
                <input type="hidden" name="target_user_id" value="<?= $other->id ?>">
                <strong><?= htmlspecialchars($other->displayName, ENT_QUOTES) ?></strong>
                <div class="checkbox-grid">
                    <?php foreach ($fieldLabels as $field => $label): ?>
                        <label>
                            <input type="checkbox" name="field[<?= $field ?>]" value="1"
                                <?= isChecked($ruleIndex, VisibilityEngine::DIRECTION_USER_USER, $other->id, $field, false) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="primary">Save override for <?= htmlspecialchars($other->displayName, ENT_QUOTES) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
