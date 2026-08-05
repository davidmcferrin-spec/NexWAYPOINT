<?php

declare(strict_types=1);

/**
 * Stream an expense receipt file for the authenticated owner.
 */

use NexWaypoint\Receipts\ExpenseReceiptRepository;
use NexWaypoint\Receipts\ReceiptFileStore;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

if (!$app['db']->tableExists('expense_receipts')) {
    http_response_code(503);
    echo 'Receipts are not available until migrate.php creates expense_receipts.';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$repo = new ExpenseReceiptRepository($app['db'], $app['logger']);
$receipt = $id > 0 ? $repo->findForOwner($id, $user->id) : null;
if ($receipt === null) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

try {
    $expires = new DateTimeImmutable($receipt->expiresAt);
    if ($expires <= new DateTimeImmutable('now')) {
        http_response_code(410);
        echo 'This receipt has expired.';
        exit;
    }
} catch (Exception) {
    http_response_code(410);
    echo 'This receipt has expired.';
    exit;
}

$store = new ReceiptFileStore(NEXWAYPOINT_ROOT . '/storage/receipts', $app['logger']);
$absolute = $store->absolutePath($receipt->filePath);
if ($absolute === null) {
    http_response_code(404);
    echo 'Receipt file missing.';
    exit;
}

$filename = $receipt->downloadFilename();
$filename = str_replace(['"', "\r", "\n"], '', $filename);

header('Content-Type: ' . $receipt->mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($absolute));
header('X-Content-Type-Options: nosniff');
readfile($absolute);
exit;
