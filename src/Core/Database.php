<?php

declare(strict_types=1);

namespace NexWaypoint\Core;

/**
 * PDO wrapper supporting both MySQL (production/DreamHost) and SQLite
 * (local dev + automated tests) via DB_DRIVER. Every write helper accepts
 * an optional $actorUserId + audit description so callers can write to
 * audit_log in the same breath as the actual write -- this is how "DB admin
 * has zero visibility into who-did-what without a trail" gets enforced.
 */
final class Database
{
    private static ?Database $instance = null;

    private \PDO $pdo;
    private string $driver;
    private Logger $logger;

    private function __construct(\PDO $pdo, string $driver, Logger $logger)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->logger = $logger;
    }

    public static function fromEnv(Logger $logger): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = Env::get('DB_DRIVER', 'mysql');

        if ($driver === 'sqlite') {
            $path = Env::getRequired('DB_SQLITE_PATH');
            $dsn = "sqlite:{$path}";
            $pdo = new \PDO($dsn, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } elseif ($driver === 'mysql') {
            $host = Env::getRequired('DB_HOST');
            $port = Env::get('DB_PORT', '3306');
            $name = Env::getRequired('DB_NAME');
            $user = Env::getRequired('DB_USER');
            $pass = Env::getRequired('DB_PASSWORD');
            $charset = Env::get('DB_CHARSET', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            throw new \RuntimeException("Unsupported DB_DRIVER '{$driver}'. Use 'mysql' or 'sqlite'.");
        }

        self::$instance = new self($pdo, $driver, $logger);
        return self::$instance;
    }

    /**
     * For tests: inject an already-open PDO (e.g. in-memory SQLite) instead
     * of building one from environment variables.
     */
    public static function fromPdo(\PDO $pdo, string $driver, Logger $logger): self
    {
        self::$instance = new self($pdo, $driver, $logger);
        return self::$instance;
    }

    public static function resetForTesting(): void
    {
        self::$instance = null;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->logger->error('DB fetchAll failed', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\PDOException $e) {
            $this->logger->error('DB fetchOne failed', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error('DB execute failed', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return false;
        }

        if ($this->driver === 'sqlite') {
            $row = $this->pdo->query(
                "SELECT 1 AS hit FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote($table)
            )->fetch();
            return $row !== false && $row !== null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 AS hit FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
        );
        $stmt->execute(['table' => $table]);
        return $stmt->fetch() !== false;
    }

    public function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return false;
        }

        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
            if ($stmt === false) {
                return false;
            }
            while ($row = $stmt->fetch()) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 AS hit FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return $stmt->fetch() !== false;
    }

    /**
     * Explicit audit-log write. Repositories call this after every insert/
     * update/delete on a record that matters (hotel_stays, trips,
     * visibility_rules, users). Never wired implicitly/magically -- an
     * explicit call site is easier to reason about and test.
     *
     * @param array<string, mixed> $details
     */
    public function audit(?int $actorUserId, string $action, string $table, ?int $recordId, array $details = []): void
    {
        $this->execute(
            'INSERT INTO audit_log (actor_user_id, action, table_name, record_id, details) VALUES (:actor, :action, :table_name, :record_id, :details)',
            [
                'actor' => $actorUserId,
                'action' => $action,
                'table_name' => $table,
                'record_id' => $recordId,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES) ?: '{}',
            ]
        );
    }
}
