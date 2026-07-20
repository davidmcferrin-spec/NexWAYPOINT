<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

/**
 * Site-wide office / venue catalog entry (walk-to suggestions + map pins).
 */
final class OfficeVenue
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly ?string $addressLine1,
        public readonly ?string $city,
        public readonly ?string $stateRegion,
        public readonly ?string $postalCode,
        public readonly string $country,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $notes,
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
            addressLine1: $row['address_line1'] ?? null,
            city: $row['city'] ?? null,
            stateRegion: $row['state_region'] ?? null,
            postalCode: $row['postal_code'] ?? null,
            country: (string) ($row['country'] ?? 'USA'),
            latitude: isset($row['latitude']) && $row['latitude'] !== '' && $row['latitude'] !== null
                ? (float) $row['latitude'] : null,
            longitude: isset($row['longitude']) && $row['longitude'] !== '' && $row['longitude'] !== null
                ? (float) $row['longitude'] : null,
            notes: $row['notes'] ?? null,
            isActive: (bool) ($row['is_active'] ?? true),
        );
    }

    public function placeLabel(): string
    {
        return trim(implode(', ', array_filter([
            $this->addressLine1,
            $this->city,
            $this->stateRegion,
            $this->postalCode,
            $this->country !== 'USA' ? $this->country : null,
        ], static fn ($v) => $v !== null && trim((string) $v) !== '')));
    }
}
