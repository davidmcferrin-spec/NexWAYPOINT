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

    if ($tableExists('hotel_properties') && !$columnExists('hotel_properties', 'phone')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE hotel_properties ADD COLUMN phone TEXT NULL');
        } else {
            $pdo->exec('ALTER TABLE hotel_properties ADD COLUMN phone VARCHAR(40) NULL');
        }
        $changes++;
        fwrite(STDOUT, "Added hotel_properties.phone\n");
    }

    if ($tableExists('hotel_properties') && !$columnExists('hotel_properties', 'has_destination_fee')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE hotel_properties ADD COLUMN has_destination_fee INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE hotel_properties ADD COLUMN destination_fee_notes TEXT NULL');
        } else {
            $pdo->exec('ALTER TABLE hotel_properties ADD COLUMN has_destination_fee TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE hotel_properties ADD COLUMN destination_fee_notes VARCHAR(255) NULL');
        }
        $changes++;
        fwrite(STDOUT, "Added hotel_properties destination fee columns\n");
    }

    if (!$tableExists('carriers')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE carriers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    name TEXT NOT NULL,
                    iata_code TEXT NULL,
                    carrier_type TEXT NOT NULL DEFAULT 'airline' CHECK (carrier_type IN ('airline','rail')),
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE (carrier_type, iata_code)
                )"
            );
            $pdo->exec('CREATE INDEX idx_carriers_user ON carriers(user_id)');
            $pdo->exec('CREATE INDEX idx_carriers_name ON carriers(name)');
            $pdo->exec('CREATE INDEX idx_carriers_type ON carriers(carrier_type)');
        } else {
            $pdo->exec(
                "CREATE TABLE carriers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    iata_code VARCHAR(3) NULL,
                    carrier_type ENUM('airline','rail') NOT NULL DEFAULT 'airline',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_carriers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE KEY uq_carrier_type_iata (carrier_type, iata_code),
                    INDEX idx_carriers_user (user_id),
                    INDEX idx_carriers_name (name),
                    INDEX idx_carriers_type (carrier_type)
                ) ENGINE=InnoDB"
            );
        }
        $changes++;
        fwrite(STDOUT, "Created carriers\n");
    }

    if ($tableExists('carriers') && !$columnExists('carriers', 'carrier_type')) {
        if ($driver === 'sqlite') {
            $pdo->exec("ALTER TABLE carriers ADD COLUMN carrier_type TEXT NOT NULL DEFAULT 'airline'");
        } else {
            $pdo->exec(
                "ALTER TABLE carriers ADD COLUMN carrier_type ENUM('airline','rail') NOT NULL DEFAULT 'airline'"
            );
            $pdo->exec('CREATE INDEX idx_carriers_type ON carriers (carrier_type)');
        }
        // Amtrak / 2V rows from mail import should be rail operators.
        $pdo->exec(
            "UPDATE carriers SET carrier_type = 'rail'
             WHERE UPPER(COALESCE(iata_code, '')) = '2V'
                OR LOWER(name) LIKE '%amtrak%'"
        );
        $changes++;
        fwrite(STDOUT, "Added carriers.carrier_type\n");
    }

    // Convert per-user carriers → site-wide catalog (unique by type + IATA).
    if ($tableExists('carriers') && $columnExists('carriers', 'carrier_type')) {
        $needsSiteWide = false;
        if ($driver === 'mysql') {
            $idx = $pdo->query("SHOW INDEX FROM carriers WHERE Key_name = 'uq_carrier_user_iata'")->fetchAll();
            $needsSiteWide = $idx !== [];
            if (!$needsSiteWide) {
                $haveNew = $pdo->query("SHOW INDEX FROM carriers WHERE Key_name = 'uq_carrier_type_iata'")->fetchAll();
                // Fresh installs already have the new key; legacy may lack both briefly.
                $needsSiteWide = $haveNew === [] && $idx !== [];
            }
        } else {
            $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='carriers'")->fetchColumn();
            $needsSiteWide = str_contains($sql, 'UNIQUE (user_id, iata_code)')
                || str_contains($sql, 'UNIQUE(user_id, iata_code)');
        }

        if ($needsSiteWide) {
            $dups = $pdo->query(
                "SELECT carrier_type, UPPER(iata_code) AS iata, MIN(id) AS keep_id
                 FROM carriers
                 WHERE iata_code IS NOT NULL AND TRIM(iata_code) != ''
                 GROUP BY carrier_type, UPPER(iata_code)
                 HAVING COUNT(*) > 1"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($dups as $dup) {
                $keepId = (int) $dup['keep_id'];
                $losers = $pdo->prepare(
                    'SELECT id FROM carriers
                     WHERE carrier_type = :t AND UPPER(iata_code) = :i AND id != :keep'
                );
                $losers->execute([
                    't' => (string) $dup['carrier_type'],
                    'i' => (string) $dup['iata'],
                    'keep' => $keepId,
                ]);
                foreach ($losers->fetchAll(PDO::FETCH_ASSOC) as $loser) {
                    $loserId = (int) $loser['id'];
                    if ($tableExists('trip_segments') && $columnExists('trip_segments', 'carrier_id')) {
                        $pdo->prepare(
                            'UPDATE trip_segments SET carrier_id = :keep WHERE carrier_id = :old'
                        )->execute(['keep' => $keepId, 'old' => $loserId]);
                    }
                    $pdo->prepare('DELETE FROM carriers WHERE id = :id')->execute(['id' => $loserId]);
                }
            }

            if ($driver === 'mysql') {
                try {
                    $pdo->exec('ALTER TABLE carriers DROP INDEX uq_carrier_user_iata');
                } catch (Throwable $e) {
                    // already gone
                }
                try {
                    $pdo->exec('ALTER TABLE carriers ADD UNIQUE KEY uq_carrier_type_iata (carrier_type, iata_code)');
                } catch (Throwable $e) {
                    fwrite(STDERR, 'Note: could not add uq_carrier_type_iata: ' . $e->getMessage() . "\n");
                }
            } else {
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $pdo->exec(
                    "CREATE TABLE carriers_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        name TEXT NOT NULL,
                        iata_code TEXT NULL,
                        carrier_type TEXT NOT NULL DEFAULT 'airline',
                        created_at TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                        UNIQUE (carrier_type, iata_code)
                    )"
                );
                $pdo->exec(
                    'INSERT INTO carriers_new (id, user_id, name, iata_code, carrier_type, created_at, updated_at)
                     SELECT id, user_id, name, iata_code, carrier_type, created_at, updated_at FROM carriers'
                );
                $pdo->exec('DROP TABLE carriers');
                $pdo->exec('ALTER TABLE carriers_new RENAME TO carriers');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_carriers_user ON carriers(user_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_carriers_name ON carriers(name)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_carriers_type ON carriers(carrier_type)');
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
            $changes++;
            fwrite(STDOUT, "Converted carriers to site-wide catalog\n");
        }
    }

    if ($tableExists('trip_segments') && !$columnExists('trip_segments', 'carrier_id')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE trip_segments ADD COLUMN carrier_id INTEGER NULL');
        } else {
            $pdo->exec('ALTER TABLE trip_segments ADD COLUMN carrier_id INT UNSIGNED NULL');
            $pdo->exec(
                'ALTER TABLE trip_segments
                 ADD CONSTRAINT fk_segments_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id) ON DELETE SET NULL'
            );
            $pdo->exec('CREATE INDEX idx_segments_carrier ON trip_segments (carrier_id)');
        }
        $changes++;
        fwrite(STDOUT, "Added trip_segments.carrier_id\n");
    }

    // Backfill carriers from free-text trip_segments.carrier names
    if ($tableExists('carriers') && $tableExists('trip_segments') && $columnExists('trip_segments', 'carrier_id')) {
        $rows = $pdo->query(
            "SELECT ts.id AS segment_id, ts.carrier AS carrier_name, t.owner_id AS user_id
             FROM trip_segments ts
             INNER JOIN trips t ON t.id = ts.trip_id
             WHERE ts.segment_type = 'flight'
               AND ts.carrier_id IS NULL
               AND ts.carrier IS NOT NULL
               AND TRIM(ts.carrier) != ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows !== []) {
            $findCarrier = $pdo->prepare(
                'SELECT id FROM carriers WHERE user_id = :uid AND LOWER(name) = LOWER(:name) LIMIT 1'
            );
            $insertCarrier = $pdo->prepare(
                'INSERT INTO carriers (user_id, name, iata_code) VALUES (:uid, :name, NULL)'
            );
            $linkSegment = $pdo->prepare('UPDATE trip_segments SET carrier_id = :cid WHERE id = :id');
            $cache = [];
            foreach ($rows as $row) {
                $userId = (int) $row['user_id'];
                $name = trim((string) $row['carrier_name']);
                $cacheKey = $userId . '|' . strtolower($name);
                if (!isset($cache[$cacheKey])) {
                    $findCarrier->execute(['uid' => $userId, 'name' => $name]);
                    $existingId = $findCarrier->fetchColumn();
                    if ($existingId !== false) {
                        $cache[$cacheKey] = (int) $existingId;
                    } else {
                        $insertCarrier->execute(['uid' => $userId, 'name' => $name]);
                        $cache[$cacheKey] = (int) $pdo->lastInsertId();
                    }
                }
                $linkSegment->execute(['cid' => $cache[$cacheKey], 'id' => $row['segment_id']]);
            }
            $changes++;
            fwrite(STDOUT, 'Backfilled ' . count($cache) . " carriers from flight segments\n");
        }
    }

    if (!$tableExists('user_emails')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE user_emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    email TEXT NOT NULL UNIQUE,
                    label TEXT NULL,
                    is_primary INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )"
            );
            $pdo->exec('CREATE INDEX idx_user_emails_user ON user_emails(user_id)');
        } else {
            $pdo->exec(
                "CREATE TABLE user_emails (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    label VARCHAR(100) NULL,
                    is_primary TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_user_emails_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE KEY uq_user_emails_email (email),
                    INDEX idx_user_emails_user (user_id)
                ) ENGINE=InnoDB"
            );
        }
        $changes++;
        fwrite(STDOUT, "Created user_emails\n");
    }

    // Seed primary users.email into user_emails for existing accounts.
    if ($tableExists('user_emails') && $tableExists('users')) {
        $missing = $pdo->query(
            "SELECT u.id, u.email FROM users u
             WHERE u.email IS NOT NULL AND TRIM(u.email) != ''
               AND NOT EXISTS (
                 SELECT 1 FROM user_emails ue
                 WHERE LOWER(ue.email) = LOWER(u.email)
               )"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($missing !== []) {
            $ins = $pdo->prepare(
                'INSERT INTO user_emails (user_id, email, label, is_primary) VALUES (:uid, :email, :label, 1)'
            );
            foreach ($missing as $row) {
                $ins->execute([
                    'uid' => (int) $row['id'],
                    'email' => trim((string) $row['email']),
                    'label' => 'Primary',
                ]);
            }
            $changes++;
            fwrite(STDOUT, 'Backfilled ' . count($missing) . " primary addresses into user_emails\n");
        }
    }

    if ($tableExists('users') && !$columnExists('users', 'is_admin')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0');
        } else {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0');
        }
        // Prior installs used role=manager for admin screens.
        $pdo->exec("UPDATE users SET is_admin = 1 WHERE role = 'manager' OR LOWER(username) = 'admin'");
        $changes++;
        fwrite(STDOUT, "Added users.is_admin (seeded from legacy manager role)\n");
    }

    if ($tableExists('users') && !$columnExists('users', 'is_system')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_system INTEGER NOT NULL DEFAULT 0');
        } else {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0');
        }
        // Bootstrap account from seed_admin.php — isolated from the org chart.
        $pdo->exec("UPDATE users SET is_system = 1, manager_id = NULL WHERE LOWER(username) = 'admin'");
        if ($tableExists('user_dotted_managers')) {
            $pdo->exec(
                "DELETE FROM user_dotted_managers
                 WHERE user_id IN (SELECT id FROM users WHERE is_system = 1)
                    OR manager_id IN (SELECT id FROM users WHERE is_system = 1)"
            );
        }
        $changes++;
        fwrite(STDOUT, "Added users.is_system (seeded admin isolated from org chart)\n");
    }

    if (!$tableExists('user_dotted_managers')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE user_dotted_managers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    manager_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE (user_id, manager_id)
                )"
            );
            $pdo->exec('CREATE INDEX idx_dotted_user ON user_dotted_managers(user_id)');
            $pdo->exec('CREATE INDEX idx_dotted_manager ON user_dotted_managers(manager_id)');
        } else {
            $pdo->exec(
                "CREATE TABLE user_dotted_managers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    manager_id INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_dotted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_dotted_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE KEY uq_dotted (user_id, manager_id),
                    INDEX idx_dotted_user (user_id),
                    INDEX idx_dotted_manager (manager_id)
                ) ENGINE=InnoDB"
            );
        }
        $changes++;
        fwrite(STDOUT, "Created user_dotted_managers\n");
    }

    if (!$tableExists('hotel_brands')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE hotel_brands (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                )"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE hotel_brands (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_hotel_brands_name (name)
                ) ENGINE=InnoDB"
            );
        }
        $seed = $pdo->prepare('INSERT INTO hotel_brands (name, sort_order, is_active) VALUES (:n, :s, 1)');
        $defaults = [
            ['Marriott', 10],
            ['Hilton', 20],
            ['IHG', 30],
            ['Hyatt', 40],
            ['Choice Hotels', 50],
        ];
        foreach ($defaults as [$name, $sort]) {
            $seed->execute(['n' => $name, 's' => $sort]);
        }
        $changes++;
        fwrite(STDOUT, "Created hotel_brands and seeded top 5 brands\n");
    }

    if (!$tableExists('office_venues')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE office_venues (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    address_line1 TEXT NULL,
                    city TEXT NULL,
                    state_region TEXT NULL,
                    postal_code TEXT NULL,
                    country TEXT NOT NULL DEFAULT 'USA',
                    latitude REAL NULL,
                    longitude REAL NULL,
                    notes TEXT NULL,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                )"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE office_venues (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(150) NOT NULL,
                    address_line1 VARCHAR(255) NULL,
                    city VARCHAR(100) NULL,
                    state_region VARCHAR(100) NULL,
                    postal_code VARCHAR(20) NULL,
                    country VARCHAR(100) NOT NULL DEFAULT 'USA',
                    latitude DECIMAL(10, 7) NULL,
                    longitude DECIMAL(10, 7) NULL,
                    notes VARCHAR(255) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_office_venues_name (name)
                ) ENGINE=InnoDB"
            );
        }
        $changes++;
        fwrite(STDOUT, "Created office_venues\n");
    }

    if (!$tableExists('cron_job_runs')) {
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE cron_job_runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_name TEXT NOT NULL,
                    started_at TEXT NOT NULL,
                    finished_at TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running','ok','warning','failed')),
                    summary_json TEXT NULL,
                    error_class TEXT NULL,
                    error_message TEXT NULL
                )"
            );
            $pdo->exec('CREATE INDEX idx_cron_runs_job_started ON cron_job_runs(job_name, started_at)');
            $pdo->exec('CREATE INDEX idx_cron_runs_started ON cron_job_runs(started_at)');
        } else {
            $pdo->exec(
                "CREATE TABLE cron_job_runs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    job_name VARCHAR(60) NOT NULL,
                    started_at DATETIME NOT NULL,
                    finished_at DATETIME NULL,
                    status ENUM('running','ok','warning','failed') NOT NULL DEFAULT 'running',
                    summary_json JSON NULL,
                    error_class VARCHAR(120) NULL,
                    error_message VARCHAR(500) NULL,
                    INDEX idx_cron_runs_job_started (job_name, started_at),
                    INDEX idx_cron_runs_started (started_at)
                ) ENGINE=InnoDB"
            );
        }
        $changes++;
        fwrite(STDOUT, "Created cron_job_runs\n");
    }

    if ($tableExists('cron_job_runs') && !$columnExists('cron_job_runs', 'error_message')) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE cron_job_runs ADD COLUMN error_message TEXT NULL');
        } else {
            $pdo->exec('ALTER TABLE cron_job_runs ADD COLUMN error_message VARCHAR(500) NULL');
        }
        $changes++;
        fwrite(STDOUT, "Added cron_job_runs.error_message\n");
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
