<?php

declare(strict_types=1);

/**
 * Idempotent schema upgrades for existing installs. Safe to run after every
 * setup.sh install/update. Fresh installs already get these from schema.sql.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    /** @var array{db: \NexWaypoint\Core\Database} $app */
    $app = require dirname(__DIR__) . '/config/bootstrap.php';
    $db = $app['db'];
    $pdo = $db->pdo();
    $driver = $db->driver();
    $changes = 0;

    $tableExists = static function (string $table) use ($pdo, $driver): bool {
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t");
            $stmt->execute(['t' => $table]);
            return (bool) $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $stmt->execute(['t' => $table]);
        return (bool) $stmt->fetchColumn();
    };

    $columnExists = static function (string $table, string $column) use ($pdo, $driver): bool {
        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (bool) $stmt->fetchColumn();
    };

    if ($tableExists('hotel_stays') && !$columnExists('hotel_stays', 'is_private')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE hotel_stays ADD COLUMN is_private INTEGER NOT NULL DEFAULT 0');
        } else {
            $pdo->exec('ALTER TABLE hotel_stays ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec('CREATE INDEX idx_hotel_private ON hotel_stays (is_private)');
        }
        $changes++;
        fwrite(STDOUT, "Added hotel_stays.is_private\n");
    }

    if (!$tableExists('visibility_blocks')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE visibility_blocks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    owner_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    resource_type TEXT NOT NULL CHECK (resource_type IN ('hotel_stay','trip')),
                    resource_id INTEGER NOT NULL,
                    blocked_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE (resource_type, resource_id, blocked_user_id)
                )"
            );
            $pdo->exec('CREATE INDEX idx_vis_block_resource ON visibility_blocks(resource_type, resource_id)');
            $pdo->exec('CREATE INDEX idx_vis_block_owner ON visibility_blocks(owner_user_id)');
        } else {
            $pdo->exec(
                "CREATE TABLE visibility_blocks (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    owner_user_id INT UNSIGNED NOT NULL,
                    resource_type ENUM('hotel_stay','trip') NOT NULL,
                    resource_id INT UNSIGNED NOT NULL,
                    blocked_user_id INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_vis_block_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_vis_block_target FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE KEY uq_vis_block (resource_type, resource_id, blocked_user_id),
                    INDEX idx_vis_block_resource (resource_type, resource_id),
                    INDEX idx_vis_block_owner (owner_user_id)
                ) ENGINE=InnoDB"
            );
        }
        $changes++;
        fwrite(STDOUT, "Created visibility_blocks\n");
    }

    // --- hotel_properties split ------------------------------------------------
    if (!$tableExists('hotel_properties')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE hotel_properties (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    hotel_name TEXT NOT NULL,
                    brand TEXT NULL,
                    address_line1 TEXT NULL,
                    address_line2 TEXT NULL,
                    city TEXT NULL,
                    state_region TEXT NULL,
                    postal_code TEXT NULL,
                    country TEXT NULL,
                    latitude REAL NULL,
                    longitude REAL NULL,
                    has_desk INTEGER NOT NULL DEFAULT 0,
                    desk_notes TEXT NULL,
                    has_pool INTEGER NOT NULL DEFAULT 0,
                    has_hot_tub INTEGER NOT NULL DEFAULT 0,
                    has_breakfast INTEGER NOT NULL DEFAULT 0,
                    breakfast_notes TEXT NULL,
                    has_gym INTEGER NOT NULL DEFAULT 0,
                    has_free_parking INTEGER NOT NULL DEFAULT 0,
                    has_airport_shuttle INTEGER NOT NULL DEFAULT 0,
                    has_ev_charging INTEGER NOT NULL DEFAULT 0,
                    has_onsite_restaurant INTEGER NOT NULL DEFAULT 0,
                    has_offsite_gym INTEGER NOT NULL DEFAULT 0,
                    walk_to_office INTEGER NOT NULL DEFAULT 0,
                    walk_to_office_notes TEXT NULL,
                    wifi_quality INTEGER NULL,
                    noise_level INTEGER NULL,
                    unique_features TEXT NULL,
                    is_blacklisted INTEGER NOT NULL DEFAULT 0,
                    blacklist_reason TEXT NULL,
                    overall_rating REAL NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                )"
            );
            $pdo->exec('CREATE INDEX idx_prop_user ON hotel_properties(user_id)');
            $pdo->exec('CREATE INDEX idx_prop_city ON hotel_properties(city)');
            $pdo->exec('CREATE INDEX idx_prop_blacklist ON hotel_properties(is_blacklisted)');
            $pdo->exec('CREATE INDEX idx_prop_name ON hotel_properties(hotel_name)');
        } else {
            $pdo->exec(
                "CREATE TABLE hotel_properties (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    hotel_name VARCHAR(200) NOT NULL,
                    brand VARCHAR(100) NULL,
                    address_line1 VARCHAR(200) NULL,
                    address_line2 VARCHAR(200) NULL,
                    city VARCHAR(120) NULL,
                    state_region VARCHAR(120) NULL,
                    postal_code VARCHAR(20) NULL,
                    country VARCHAR(80) NULL,
                    latitude DECIMAL(10,7) NULL,
                    longitude DECIMAL(10,7) NULL,
                    has_desk TINYINT(1) NOT NULL DEFAULT 0,
                    desk_notes VARCHAR(255) NULL,
                    has_pool TINYINT(1) NOT NULL DEFAULT 0,
                    has_hot_tub TINYINT(1) NOT NULL DEFAULT 0,
                    has_breakfast TINYINT(1) NOT NULL DEFAULT 0,
                    breakfast_notes VARCHAR(255) NULL,
                    has_gym TINYINT(1) NOT NULL DEFAULT 0,
                    has_free_parking TINYINT(1) NOT NULL DEFAULT 0,
                    has_airport_shuttle TINYINT(1) NOT NULL DEFAULT 0,
                    has_ev_charging TINYINT(1) NOT NULL DEFAULT 0,
                    has_onsite_restaurant TINYINT(1) NOT NULL DEFAULT 0,
                    has_offsite_gym TINYINT(1) NOT NULL DEFAULT 0,
                    walk_to_office TINYINT(1) NOT NULL DEFAULT 0,
                    walk_to_office_notes VARCHAR(255) NULL,
                    wifi_quality TINYINT UNSIGNED NULL,
                    noise_level TINYINT UNSIGNED NULL,
                    unique_features TEXT NULL,
                    is_blacklisted TINYINT(1) NOT NULL DEFAULT 0,
                    blacklist_reason TEXT NULL,
                    overall_rating DECIMAL(3,2) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_hotel_properties_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_prop_user (user_id),
                    INDEX idx_prop_city (city),
                    INDEX idx_prop_blacklist (is_blacklisted),
                    INDEX idx_prop_name (hotel_name)
                ) ENGINE=InnoDB"
            );
        }
        $changes++;
        fwrite(STDOUT, "Created hotel_properties\n");
    }

    $legacyMonolith = $tableExists('hotel_stays') && $columnExists('hotel_stays', 'hotel_name');

    if ($tableExists('hotel_stays') && !$columnExists('hotel_stays', 'hotel_property_id')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE hotel_stays ADD COLUMN hotel_property_id INTEGER NULL');
        } else {
            $pdo->exec('ALTER TABLE hotel_stays ADD COLUMN hotel_property_id INT UNSIGNED NULL');
        }
        $changes++;
        fwrite(STDOUT, "Added hotel_stays.hotel_property_id\n");
    }

    foreach (
        [
            'bed_type' => $driver === 'sqlite'
                ? 'ALTER TABLE hotel_stays ADD COLUMN bed_type TEXT NULL'
                : "ALTER TABLE hotel_stays ADD COLUMN bed_type ENUM('king','queen','dual_queen') NULL",
            'bathroom_type' => $driver === 'sqlite'
                ? 'ALTER TABLE hotel_stays ADD COLUMN bathroom_type TEXT NULL'
                : "ALTER TABLE hotel_stays ADD COLUMN bathroom_type ENUM('tub','walk_in_shower') NULL",
            'stay_rating' => $driver === 'sqlite'
                ? 'ALTER TABLE hotel_stays ADD COLUMN stay_rating INTEGER NULL'
                : 'ALTER TABLE hotel_stays ADD COLUMN stay_rating TINYINT UNSIGNED NULL',
        ] as $column => $sql
    ) {
        if ($tableExists('hotel_stays') && !$columnExists('hotel_stays', $column)) {
            $pdo->exec($sql);
            $changes++;
            fwrite(STDOUT, "Added hotel_stays.{$column}\n");
        }
    }

    if ($legacyMonolith && $columnExists('hotel_stays', 'rating') && $columnExists('hotel_stays', 'stay_rating')) {
        $pdo->exec('UPDATE hotel_stays SET stay_rating = rating WHERE stay_rating IS NULL AND rating IS NOT NULL');
        fwrite(STDOUT, "Copied rating -> stay_rating for legacy stays\n");
    }

    if ($legacyMonolith && $columnExists('hotel_stays', 'hotel_property_id')) {
        $stays = $pdo->query(
            'SELECT * FROM hotel_stays WHERE hotel_property_id IS NULL ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $propertyCache = [];
        foreach ($stays as $stay) {
            $userId = (int) $stay['user_id'];
            $nameKey = strtolower(trim((string) $stay['hotel_name']));
            $cityKey = strtolower(trim((string) ($stay['city'] ?? '')));
            $cacheKey = $userId . '|' . $nameKey . '|' . $cityKey;

            if (!isset($propertyCache[$cacheKey])) {
                $insert = $pdo->prepare(
                    'INSERT INTO hotel_properties (
                        user_id, hotel_name, brand, address_line1, address_line2, city, state_region,
                        postal_code, country, latitude, longitude, has_desk, desk_notes, has_pool,
                        has_hot_tub, has_breakfast, breakfast_notes, has_gym, has_free_parking,
                        has_airport_shuttle, wifi_quality, noise_level, unique_features,
                        is_blacklisted, blacklist_reason
                    ) VALUES (
                        :user_id, :hotel_name, :brand, :address_line1, :address_line2, :city, :state_region,
                        :postal_code, :country, :latitude, :longitude, :has_desk, :desk_notes, :has_pool,
                        :has_hot_tub, :has_breakfast, :breakfast_notes, :has_gym, :has_free_parking,
                        :has_airport_shuttle, :wifi_quality, :noise_level, :unique_features,
                        :is_blacklisted, :blacklist_reason
                    )'
                );
                $insert->execute([
                    'user_id' => $userId,
                    'hotel_name' => $stay['hotel_name'],
                    'brand' => $stay['brand'] ?? null,
                    'address_line1' => $stay['address_line1'] ?? null,
                    'address_line2' => $stay['address_line2'] ?? null,
                    'city' => $stay['city'] ?? null,
                    'state_region' => $stay['state_region'] ?? null,
                    'postal_code' => $stay['postal_code'] ?? null,
                    'country' => $stay['country'] ?? null,
                    'latitude' => $stay['latitude'] ?? null,
                    'longitude' => $stay['longitude'] ?? null,
                    'has_desk' => (int) ($stay['has_desk'] ?? 0),
                    'desk_notes' => $stay['desk_notes'] ?? null,
                    'has_pool' => (int) ($stay['has_pool'] ?? 0),
                    'has_hot_tub' => (int) ($stay['has_hot_tub'] ?? 0),
                    'has_breakfast' => (int) ($stay['has_breakfast'] ?? 0),
                    'breakfast_notes' => $stay['breakfast_notes'] ?? null,
                    'has_gym' => (int) ($stay['has_gym'] ?? 0),
                    'has_free_parking' => (int) ($stay['has_free_parking'] ?? 0),
                    'has_airport_shuttle' => (int) ($stay['has_airport_shuttle'] ?? 0),
                    'wifi_quality' => $stay['wifi_quality'] ?? null,
                    'noise_level' => $stay['noise_level'] ?? null,
                    'unique_features' => $stay['unique_features'] ?? null,
                    'is_blacklisted' => (int) ($stay['is_blacklisted'] ?? 0),
                    'blacklist_reason' => $stay['blacklist_reason'] ?? null,
                ]);
                $propertyCache[$cacheKey] = (int) $pdo->lastInsertId();
            }

            $upd = $pdo->prepare('UPDATE hotel_stays SET hotel_property_id = :pid WHERE id = :id');
            $upd->execute(['pid' => $propertyCache[$cacheKey], 'id' => $stay['id']]);
        }

        if ($stays !== []) {
            $changes++;
            fwrite(STDOUT, 'Backfilled ' . count($propertyCache) . " hotel_properties from legacy stays\n");
        }
    }

    // Recompute overall ratings
    if ($tableExists('hotel_properties') && $tableExists('hotel_stays') && $columnExists('hotel_stays', 'stay_rating')) {
        $props = $pdo->query('SELECT id FROM hotel_properties')->fetchAll(PDO::FETCH_COLUMN);
        $avgStmt = $pdo->prepare(
            'SELECT AVG(stay_rating) FROM hotel_stays WHERE hotel_property_id = :id AND stay_rating IS NOT NULL'
        );
        $setStmt = $pdo->prepare('UPDATE hotel_properties SET overall_rating = :r WHERE id = :id');
        foreach ($props as $propId) {
            $avgStmt->execute(['id' => $propId]);
            $avg = $avgStmt->fetchColumn();
            $setStmt->execute([
                'r' => $avg === false || $avg === null ? null : round((float) $avg, 2),
                'id' => $propId,
            ]);
        }
    }

    if ($changes === 0) {
        fwrite(STDOUT, "Schema is up to date.\n");
    } else {
        fwrite(STDOUT, "Applied {$changes} migration change(s).\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: {$exception->getMessage()}\n");
    exit(1);
}
