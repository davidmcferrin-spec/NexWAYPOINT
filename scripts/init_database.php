<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    /** @var array{db: \NexWaypoint\Core\Database} $app */
    $app = require dirname(__DIR__) . '/config/bootstrap.php';
    $db = $app['db'];
    $pdo = $db->pdo();
    $expectedTables = [
        'users',
        'user_status_overrides',
        'hotel_properties',
        'hotel_stays',
        'hotel_photos',
        'trips',
        'carriers',
        'trip_segments',
        'flight_status',
        'parse_log',
        'visibility_rules',
        'visibility_blocks',
        'aeroapi_usage_log',
        'audit_log',
        'notifications',
    ];

    if ($db->driver() === 'sqlite') {
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        $schemaPath = dirname(__DIR__) . '/database/schema.sqlite.sql';
    } else {
        $statement = $pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        $schemaPath = dirname(__DIR__) . '/database/schema.sql';
    }

    $existingTables = $statement->fetchAll(PDO::FETCH_COLUMN);
    $coreTables = array_values(array_filter(
        $expectedTables,
        static fn (string $t) => !in_array($t, ['visibility_blocks'], true)
    ));
    $installedCore = array_intersect($coreTables, $existingTables);
    if (count($installedCore) === count($coreTables)) {
        fwrite(STDOUT, "Database schema is already installed.\n");
        exit(0);
    }
    if ($installedCore !== []) {
        // Legacy installs may lack hotel_properties until migrate runs.
        $missingTables = array_diff($coreTables, $existingTables);
        if ($missingTables === ['hotel_properties'] || $missingTables === []) {
            fwrite(STDOUT, "Database schema is already installed (pending migrate for hotel_properties).\n");
            exit(0);
        }
        throw new RuntimeException(
            'Database schema is only partially installed. Missing tables: ' . implode(', ', $missingTables)
        );
    }

    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException("Unable to read schema: {$schemaPath}");
    }

    $pdo->exec($schema);
    fwrite(STDOUT, "Database schema installed successfully.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Database setup failed: {$exception->getMessage()}\n");
    exit(1);
}
