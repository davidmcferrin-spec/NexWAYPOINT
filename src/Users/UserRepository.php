<?php

declare(strict_types=1);

namespace NexWaypont\Users;

use NexWaypont\Core\Database;
use NexWaypont\Core\Logger;

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
     * Used by MailPoller to attribute a forwarded confirmation email to its
     * owner via the From: address. Case-insensitive match.
     */
    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE LOWER(email) = LOWER(:e) AND is_active = 1', ['e' => $email]);
        return $row === null ? null : User::fromRow($row);
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

    public function create(
        string $username,
        string $email,
        string $plainPassword,
        string $displayName,
        string $role,
        ?int $managerId,
        ?int $actorUserId = null
    ): User {
        if (!in_array($role, ['manager', 'peer', 'subordinate'], true)) {
            throw new \InvalidArgumentException("Invalid role '{$role}'.");
        }

        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

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

        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'users', $id, ['username' => $username]);
        $this->logger->info('User created', ['id' => $id, 'username' => $username]);

        $user = $this->find($id);
        if ($user === null) {
            throw new \RuntimeException('User insert succeeded but row could not be re-read.');
        }
        return $user;
    }
}
