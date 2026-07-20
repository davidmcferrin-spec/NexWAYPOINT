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
        public readonly bool $isSystem = false,
        public readonly ?string $photoPath = null,
        public readonly float $photoFocusX = 50.0,
        public readonly float $photoFocusY = 50.0,
        public readonly ?string $homeCity = null,
        public readonly ?string $homeState = null,
        public readonly ?float $homeLat = null,
        public readonly ?float $homeLon = null,
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
            isSystem: (bool) ($row['is_system'] ?? false),
            photoPath: isset($row['photo_path']) && $row['photo_path'] !== ''
                ? (string) $row['photo_path']
                : null,
            photoFocusX: isset($row['photo_focus_x']) ? (float) $row['photo_focus_x'] : 50.0,
            photoFocusY: isset($row['photo_focus_y']) ? (float) $row['photo_focus_y'] : 50.0,
            homeCity: isset($row['home_city']) && $row['home_city'] !== ''
                ? (string) $row['home_city']
                : null,
            homeState: isset($row['home_state']) && $row['home_state'] !== ''
                ? (string) $row['home_state']
                : null,
            homeLat: isset($row['home_lat']) && $row['home_lat'] !== '' && $row['home_lat'] !== null
                ? (float) $row['home_lat']
                : null,
            homeLon: isset($row['home_lon']) && $row['home_lon'] !== '' && $row['home_lon'] !== null
                ? (float) $row['home_lon']
                : null,
        );
    }

    public function hasPhoto(): bool
    {
        return $this->photoPath !== null && $this->photoPath !== '';
    }

    public function homeLabel(): ?string
    {
        if ($this->homeCity === null || $this->homeCity === '') {
            return null;
        }
        if ($this->homeState !== null && $this->homeState !== '') {
            return $this->homeCity . ', ' . $this->homeState;
        }
        return $this->homeCity;
    }
}
