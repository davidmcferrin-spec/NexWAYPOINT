<?php

declare(strict_types=1);

/**
 * POST endpoint for status overrides from the nav modal.
 * Redirects back to return_to (same-origin path only).
 */

use NexWaypoint\Core\Csrf;
use NexWaypoint\Trips\TripRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$returnTo = (string) ($_POST['return_to'] ?? '/dashboard/index.php');
if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
    $returnTo = '/dashboard/index.php';
}

$flashKey = 'nexwaypoint_status_flash';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}

if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
    $_SESSION[$flashKey] = ['type' => 'error', 'text' => 'Your session expired. Please resubmit.'];
    header('Location: ' . $returnTo);
    exit;
}

$tripRepo = new TripRepository($app['db'], $app['logger']);
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'set') {
        $status = (string) ($_POST['status'] ?? '');
        $expiresOn = (string) ($_POST['expires_on'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        $locationCity = trim((string) ($_POST['location_city'] ?? ''));
        $locationState = trim((string) ($_POST['location_state'] ?? ''));
        $tripRepo->setStatusOverride(
            $user->id,
            $status,
            $note !== '' ? $note : null,
            $expiresOn,
            $user->id,
            null,
            $locationCity !== '' ? $locationCity : null,
            $locationState !== '' ? $locationState : null,
        );
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'text' => 'Status override saved until ' . $expiresOn . '.',
        ];
    } elseif ($action === 'clear') {
        $tripRepo->clearStatusOverride($user->id, $user->id);
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'text' => 'Manual status override cleared.',
        ];
    } else {
        throw new InvalidArgumentException('Unknown action.');
    }
} catch (Throwable $e) {
    $_SESSION[$flashKey] = ['type' => 'error', 'text' => $e->getMessage()];
}

header('Location: ' . $returnTo);
exit;
