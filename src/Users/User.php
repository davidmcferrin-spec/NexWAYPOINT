<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly ?int $managerId,
        public readonly string $timezone,
        public readonly bool $isActive,
        public readonly bool $isAdmin = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            displayName: (string) $row['display_name'],
            role: (string) $row['role'],
            managerId: isset($row['manager_id']) ? (int) $row['manager_id'] : null,
            timezone: (string) ($row['timezone'] ?? 'America/Chicago'),
            isActive: (bool) $row['is_active'],
            isAdmin: (bool) ($row['is_admin'] ?? false),
        );
    }
}
