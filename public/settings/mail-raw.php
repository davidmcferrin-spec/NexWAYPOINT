<?php

declare(strict_types=1);

/**
 * System-admin only: stream a retained raw .eml for parse_log id.
 */

use NexWaypoint\Core\Env;
use NexWaypoint\Mail\ParseLogRepository;
use NexWaypoint\Mail\RawMailStore;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

if (!$user->isSystem) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$parseLog = new ParseLogRepository($app['db']);
$row = $id > 0 ? $parseLog->find($id) : null;
if ($row === null) {
    http_response_code(404);
    echo 'Parse log not found.';
    exit;
}

$retentionDays = max(1, (int) Env::get('MAIL_RAW_RETENTION_DAYS', '7'));
$rawStore = new RawMailStore(
    NEXWAYPOINT_ROOT . '/storage/mail_raw',
    $retentionDays,
    $app['logger'],
);

$rawPath = isset($row['raw_path']) ? (string) $row['raw_path'] : null;
$rawExpires = isset($row['raw_expires_at']) ? (string) $row['raw_expires_at'] : null;
if ($rawPath === null || $rawPath === '' || $rawStore->isExpired($rawExpires)) {
    http_response_code(410);
    echo 'Raw email no longer available (retention expired).';
    exit;
}

$absolute = $rawStore->absolutePath($rawPath);
if ($absolute === null) {
    http_response_code(404);
    echo 'Raw file missing.';
    exit;
}

$filename = 'parse-' . $id . '.eml';
header('Content-Type: message/rfc822');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($absolute));
readfile($absolute);
exit;
