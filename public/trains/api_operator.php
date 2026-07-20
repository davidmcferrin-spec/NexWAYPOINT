<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Trips\Carrier;
use NexWaypoint\Trips\CarrierRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && $raw !== '' && str_starts_with(ltrim($raw), '{')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if ($data === []) {
    $data = $_POST;
}

if (!Csrf::verify((string) ($data['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$repo = new CarrierRepository($app['db'], $app['logger']);

if (!$app['db']->tableExists('carriers')) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Database is missing the carriers table. Run: php scripts/migrate.php',
    ]);
    exit;
}

try {
    $name = trim((string) ($data['name'] ?? ''));
    $iata = strtoupper(trim((string) ($data['iata_code'] ?? '')));
    $created = $repo->create(new Carrier(
        id: null,
        userId: $user->id,
        name: $name,
        iataCode: $iata !== '' ? $iata : null,
        carrierType: Carrier::TYPE_RAIL,
    ), $user->id);

    echo json_encode([
        'ok' => true,
        'carrier' => [
            'id' => $created->id,
            'name' => $created->name,
            'iata_code' => $created->iataCode,
            'carrier_type' => $created->carrierType,
            'label' => $created->label(),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
