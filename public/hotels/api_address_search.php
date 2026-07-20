<?php

declare(strict_types=1);

use NexWaypoint\Hotels\Geocoder;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$app['auth']->requireAuth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($query) < 3) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$geocoder = new Geocoder($app['logger']);
try {
    $results = $geocoder->search($query, 6);
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $app['logger']->warning('Address search failed', ['error' => $e->getMessage()]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Address lookup failed. Try again in a moment.']);
}
