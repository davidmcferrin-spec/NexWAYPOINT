<?php

declare(strict_types=1);

namespace NexWaypont\Tests;

use NexWaypont\Core\Database;
use NexWaypont\Core\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Base test case: builds a fresh in-memory SQLite database from
 * database/schema.sqlite.sql for every test, so each test is fully
 * isolated with no shared state or fixture files.
 */
abstract class NexWaypontTestCase extends TestCase
{
    protected Database $db;
    protected Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Database::resetForTesting();

        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $schema = file_get_contents(NEXWAYPONT_ROOT . '/database/schema.sqlite.sql');
        if ($schema === false) {
            throw new \RuntimeException('Could not read schema.sqlite.sql for tests.');
        }
        $pdo->exec($schema);

        $this->logger = new Logger(sys_get_temp_dir() . '/nexwaypont_test.log');
        $this->db = Database::fromPdo($pdo, 'sqlite', $this->logger);
    }

    protected function tearDown(): void
    {
        Database::resetForTesting();
        parent::tearDown();
    }

    protected function insertUser(string $username, ?int $managerId = null, string $role = 'subordinate'): int
    {
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, display_name, role, manager_id)
             VALUES (:u, :e, :p, :d, :r, :m)',
            [
                'u' => $username,
                'e' => "{$username}@example.com",
                'p' => password_hash('test-password', PASSWORD_DEFAULT),
                'd' => ucfirst($username),
                'r' => $role,
                'm' => $managerId,
            ]
        );
        return $this->db->lastInsertId();
    }
}
