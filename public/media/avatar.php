<?php

declare(strict_types=1);

/**
 * Authenticated avatar image serving from storage/uploads/avatars.
 * Query: ?id={userId}
 */

use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
// Auth gate only; any logged-in user may view teammate avatars on the team board.
$app['auth']->requireAuth();

$userId = (int) ($_GET['id'] ?? 0);
if ($userId < 1) {
    http_response_code(404);
    exit;
}

$repo = new UserRepository($app['db'], $app['logger']);
$subject = $repo->find($userId);
if ($subject === null || !$subject->isActive || !$subject->hasPhoto()) {
    http_response_code(404);
    exit;
}

$path = $subject->photoPath;
if ($path === null || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit;
}

// Only serve files under the configured avatar upload directory.
$uploadDir = realpath(
    \NexWaypoint\Core\Env::get(
        'AVATAR_UPLOAD_DIR',
        dirname(__DIR__, 2) . '/storage/uploads/avatars'
    )
);
$realPath = realpath($path);
if ($uploadDir === false || $realPath === false || !str_starts_with($realPath, $uploadDir . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => null,
};
if ($mime === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($realPath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
exit;
