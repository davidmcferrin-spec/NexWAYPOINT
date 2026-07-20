<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

final class UserRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function find(int $id): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $row === null ? null : User::fromRow($row);
    }

    public function findByUsername(string $username): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE username = :u', ['u' => $username]);
        return $row === null ? null : User::fromRow($row);
    }

    /**
     * Attribute inbound confirmation mail to its owner via From:.
     * Matches any address in user_emails (preferred) or legacy users.email.
     */
    public function findByEmail(string $email): ?User
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        if ($this->db->tableExists('user_emails')) {
            $row = $this->db->fetchOne(
                'SELECT u.* FROM users u
                 INNER JOIN user_emails ue ON ue.user_id = u.id
                 WHERE LOWER(ue.email) = LOWER(:e) AND u.is_active = 1
                 LIMIT 1',
                ['e' => $email]
            );
            if ($row !== null) {
                return User::fromRow($row);
            }
        }

        $row = $this->db->fetchOne(
            'SELECT * FROM users WHERE LOWER(email) = LOWER(:e) AND is_active = 1',
            ['e' => $email]
        );
        return $row === null ? null : User::fromRow($row);
    }

    /**
     * @return list<array{id: int, email: string, label: ?string, is_primary: bool}>
     */
    public function emailsForUser(int $userId): array
    {
        if (!$this->db->tableExists('user_emails')) {
            $user = $this->find($userId);
            if ($user === null) {
                return [];
            }
            return [[
                'id' => 0,
                'email' => $user->email,
                'label' => 'Primary',
                'is_primary' => true,
            ]];
        }

        $rows = $this->db->fetchAll(
            'SELECT id, email, label, is_primary FROM user_emails
             WHERE user_id = :uid ORDER BY is_primary DESC, email ASC',
            ['uid' => $userId]
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'label' => $r['label'] !== null ? (string) $r['label'] : null,
            'is_primary' => !empty($r['is_primary']),
        ], $rows);
    }

    public function addEmail(int $userId, string $email, ?string $label = null, ?int $actorUserId = null): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }
        if (!$this->db->tableExists('user_emails')) {
            throw new \RuntimeException('user_emails table missing; run php scripts/migrate.php');
        }

        $taken = $this->db->fetchOne(
            'SELECT user_id FROM user_emails WHERE LOWER(email) = LOWER(:e) LIMIT 1',
            ['e' => $email]
        );
        if ($taken !== null) {
            if ((int) $taken['user_id'] === $userId) {
                throw new \InvalidArgumentException('That email is already on this account.');
            }
            throw new \InvalidArgumentException('That email is already linked to another account.');
        }

        $primaryTaken = $this->db->fetchOne(
            'SELECT id FROM users WHERE LOWER(email) = LOWER(:e) AND id != :uid LIMIT 1',
            ['e' => $email, 'uid' => $userId]
        );
        if ($primaryTaken !== null) {
            throw new \InvalidArgumentException('That email is already the primary address of another account.');
        }

        $this->db->execute(
            'INSERT INTO user_emails (user_id, email, label, is_primary) VALUES (:uid, :email, :label, 0)',
            ['uid' => $userId, 'email' => $email, 'label' => $label !== null && trim($label) !== '' ? trim($label) : null]
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'user_emails', $id, ['user_id' => $userId, 'email' => $email]);
        $this->logger->info('User email alias added', ['user_id' => $userId, 'email' => $email]);
        return $id;
    }

    public function removeEmail(int $userId, int $emailId, ?int $actorUserId = null): void
    {
        if (!$this->db->tableExists('user_emails')) {
            throw new \RuntimeException('user_emails table missing; run php scripts/migrate.php');
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM user_emails WHERE id = :id AND user_id = :uid',
            ['id' => $emailId, 'uid' => $userId]
        );
        if ($row === null) {
            throw new \InvalidArgumentException('Email address not found on this account.');
        }
        if (!empty($row['is_primary'])) {
            throw new \InvalidArgumentException('Cannot remove the primary account email. Change the primary address first.');
        }

        $this->db->execute('DELETE FROM user_emails WHERE id = :id', ['id' => $emailId]);
        $this->db->audit($actorUserId, 'delete', 'user_emails', $emailId, [
            'user_id' => $userId,
            'email' => $row['email'],
        ]);
        $this->logger->info('User email alias removed', ['user_id' => $userId, 'email' => $row['email']]);
    }

    /**
     * Row including password_hash -- only used internally by auth, never
     * returned as part of a User value object.
     *
     * @return array<string, mixed>|null
     */
    public function findAuthRowByUsername(string $username): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE username = :u AND is_active = 1',
            ['u' => $username]
        );
    }

    /**
     * @return User[]
     */
    public function findDirectSubordinates(int $managerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM users WHERE manager_id = :m AND is_active = 1 ORDER BY display_name',
            ['m' => $managerId]
        );
        return array_map(static fn (array $r) => User::fromRow($r), $rows);
    }

    /**
     * @return User[]
     */
    public function findPeers(int $userId, int $managerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM users WHERE manager_id = :m AND id != :self AND is_active = 1 ORDER BY display_name',
            ['m' => $managerId, 'self' => $userId]
        );
        return array_map(static fn (array $r) => User::fromRow($r), $rows);
    }

    /**
     * @return User[]
     */
    public function findAllActive(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM users WHERE is_active = 1 ORDER BY display_name');
        return array_map(static fn (array $r) => User::fromRow($r), $rows);
    }

    /**
     * @return User[]
     */
    public function findAll(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM users ORDER BY display_name');
        return array_map(static fn (array $r) => User::fromRow($r), $rows);
    }

    public function create(
        string $username,
        string $email,
        string $plainPassword,
        string $displayName,
        string $role,
        ?int $managerId,
        ?int $actorUserId = null,
        bool $isAdmin = false,
    ): User {
        // role is legacy; UI no longer sets it. Keep column filled for older code paths.
        if (!in_array($role, ['manager', 'peer', 'subordinate'], true)) {
            $role = 'subordinate';
        }

        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }
        $this->assertValidManager(null, $managerId);

        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

        if ($this->db->columnExists('users', 'is_admin')) {
            $this->db->execute(
                'INSERT INTO users (username, email, password_hash, display_name, role, manager_id, is_admin)
                 VALUES (:username, :email, :hash, :display_name, :role, :manager_id, :is_admin)',
                [
                    'username' => $username,
                    'email' => $email,
                    'hash' => $hash,
                    'display_name' => $displayName,
                    'role' => $role,
                    'manager_id' => $managerId,
                    'is_admin' => $isAdmin ? 1 : 0,
                ]
            );
        } else {
            $this->db->execute(
                'INSERT INTO users (username, email, password_hash, display_name, role, manager_id)
                 VALUES (:username, :email, :hash, :display_name, :role, :manager_id)',
                [
                    'username' => $username,
                    'email' => $email,
                    'hash' => $hash,
                    'display_name' => $displayName,
                    'role' => $role,
                    'manager_id' => $managerId,
                ]
            );
        }

        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'users', $id, ['username' => $username]);
        $this->logger->info('User created', ['id' => $id, 'username' => $username]);

        if ($this->db->tableExists('user_emails')) {
            $this->db->execute(
                'INSERT INTO user_emails (user_id, email, label, is_primary) VALUES (:uid, :email, :label, 1)',
                ['uid' => $id, 'email' => $email, 'label' => 'Primary']
            );
        }

        $user = $this->find($id);
        if ($user === null) {
            throw new \RuntimeException('User insert succeeded but row could not be re-read.');
        }
        return $user;
    }

    public function updateProfile(
        int $userId,
        string $displayName,
        ?int $managerId,
        bool $isActive,
        bool $isAdmin,
        ?int $actorUserId = null,
    ): User {
        $this->assertValidManager($userId, $managerId);

        if ($this->db->columnExists('users', 'is_admin')) {
            $this->db->execute(
                'UPDATE users SET display_name = :d, manager_id = :m, is_active = :a, is_admin = :admin,
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'd' => trim($displayName),
                    'm' => $managerId,
                    'a' => $isActive ? 1 : 0,
                    'admin' => $isAdmin ? 1 : 0,
                    'id' => $userId,
                ]
            );
        } else {
            $this->db->execute(
                'UPDATE users SET display_name = :d, manager_id = :m, is_active = :a,
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'd' => trim($displayName),
                    'm' => $managerId,
                    'a' => $isActive ? 1 : 0,
                    'id' => $userId,
                ]
            );
        }
        $this->db->audit($actorUserId, 'update', 'users', $userId, [
            'display_name' => $displayName,
            'manager_id' => $managerId,
            'is_active' => $isActive,
            'is_admin' => $isAdmin,
        ]);

        $user = $this->find($userId);
        if ($user === null) {
            throw new \RuntimeException('User update succeeded but row could not be re-read.');
        }
        return $user;
    }

    public function updatePassword(int $userId, string $plainPassword, ?int $actorUserId = null): void
    {
        if (strlen($plainPassword) < 12) {
            throw new \InvalidArgumentException('Password must be at least 12 characters.');
        }

        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $this->db->execute(
            'UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['hash' => $hash, 'id' => $userId]
        );
        $this->db->audit($actorUserId, 'update_password', 'users', $userId, []);
        $this->logger->info('User password updated', ['id' => $userId]);
    }

    /**
     * @return list<int>
     */
    public function dottedManagerIds(int $userId): array
    {
        if (!$this->db->tableExists('user_dotted_managers')) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT manager_id FROM user_dotted_managers WHERE user_id = :uid ORDER BY manager_id',
            ['uid' => $userId]
        );
        return array_map(static fn (array $r) => (int) $r['manager_id'], $rows);
    }

    /**
     * @return User[]
     */
    public function dottedManagers(int $userId): array
    {
        $ids = $this->dottedManagerIds($userId);
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $u = $this->find($id);
            if ($u !== null) {
                $out[] = $u;
            }
        }
        return $out;
    }

    /**
     * Replace dotted-line managers for a user.
     *
     * @param list<int> $managerIds
     */
    public function setDottedManagers(int $userId, array $managerIds, ?int $actorUserId = null): void
    {
        if (!$this->db->tableExists('user_dotted_managers')) {
            throw new \RuntimeException('user_dotted_managers table missing; run php scripts/migrate.php');
        }

        $clean = [];
        foreach ($managerIds as $mid) {
            $mid = (int) $mid;
            if ($mid < 1 || $mid === $userId) {
                continue;
            }
            if ($this->find($mid) === null) {
                throw new \InvalidArgumentException("Dotted-line manager #{$mid} not found.");
            }
            $clean[$mid] = $mid;
        }

        $user = $this->find($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found.');
        }
        if ($user->managerId !== null && isset($clean[$user->managerId])) {
            unset($clean[$user->managerId]); // solid line already covers this
        }

        $this->db->execute('DELETE FROM user_dotted_managers WHERE user_id = :uid', ['uid' => $userId]);
        foreach ($clean as $mid) {
            $this->db->execute(
                'INSERT INTO user_dotted_managers (user_id, manager_id) VALUES (:uid, :mid)',
                ['uid' => $userId, 'mid' => $mid]
            );
        }
        $this->db->audit($actorUserId, 'set_dotted_managers', 'user_dotted_managers', $userId, [
            'manager_ids' => array_values($clean),
        ]);
    }

    public function hasDottedReport(int $managerId, int $userId): bool
    {
        if (!$this->db->tableExists('user_dotted_managers')) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM user_dotted_managers WHERE user_id = :uid AND manager_id = :mid LIMIT 1',
            ['uid' => $userId, 'mid' => $managerId]
        );
        return $row !== null;
    }

    public function isAdmin(User $user): bool
    {
        if ($this->db->columnExists('users', 'is_admin')) {
            return $user->isAdmin;
        }
        // Pre-migration fallback
        return $user->role === 'manager';
    }

    /** @deprecated Use isAdmin() — org title is manager_id, not role. */
    public function isManager(User $user): bool
    {
        return $this->isAdmin($user);
    }

    private function assertValidManager(?int $userId, ?int $managerId): void
    {
        if ($managerId === null) {
            return;
        }
        if ($userId !== null && $managerId === $userId) {
            throw new \InvalidArgumentException('A user cannot report to themselves.');
        }
        $manager = $this->find($managerId);
        if ($manager === null || !$manager->isActive) {
            throw new \InvalidArgumentException('Selected manager was not found (or is inactive).');
        }
        // Prevent cycles: walk up from proposed manager; must not hit userId.
        if ($userId !== null) {
            $seen = [];
            $cursor = $manager;
            while ($cursor !== null && $cursor->managerId !== null) {
                if ($cursor->managerId === $userId) {
                    throw new \InvalidArgumentException('That reporting line would create a cycle in the org chart.');
                }
                if (isset($seen[$cursor->managerId])) {
                    break;
                }
                $seen[$cursor->managerId] = true;
                $cursor = $this->find($cursor->managerId);
            }
        }
    }
}
