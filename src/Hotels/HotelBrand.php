<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

/**
 * Site-wide hotel brand catalog entry (dropdown options for properties).
 */
final class HotelBrand
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly int $sortOrder,
        public readonly bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            name: (string) $row['name'],
            sortOrder: (int) ($row['sort_order'] ?? 0),
            isActive: (bool) ($row['is_active'] ?? true),
        );
    }
}
