<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

final class Trip
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $ownerId,
        public readonly string $destinationCity,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $status,
        public readonly ?string $tripPurpose,
        public readonly ?string $notes,
        public readonly bool $isPrivate,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            ownerId: (int) $row['owner_id'],
            destinationCity: (string) $row['destination_city'],
            startDate: (string) $row['start_date'],
            endDate: (string) $row['end_date'],
            status: (string) $row['status'],
            tripPurpose: $row['trip_purpose'] ?? null,
            notes: $row['notes'] ?? null,
            isPrivate: (bool) $row['is_private'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->ownerId,
            'destination_city' => $this->destinationCity,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'status' => $this->status,
            'trip_purpose' => $this->tripPurpose,
            'notes' => $this->notes,
            'is_private' => $this->isPrivate,
        ];
    }
}
