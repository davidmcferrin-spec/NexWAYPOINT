<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * Site-wide office / venue catalog for walk-to combobox + hotel map.
 */
final class OfficeVenueRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function tableReady(): bool
    {
        return $this->db->tableExists('office_venues');
    }

    public function find(int $id): ?OfficeVenue
    {
        if (!$this->tableReady()) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT * FROM office_venues WHERE id = :id', ['id' => $id]);
        return $row === null ? null : OfficeVenue::fromRow($row);
    }

    /**
     * @return OfficeVenue[]
     */
    public function findActive(): array
    {
        if (!$this->tableReady()) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM office_venues WHERE is_active = 1 ORDER BY name ASC'
        );
        return array_map(static fn (array $r) => OfficeVenue::fromRow($r), $rows);
    }

    /**
     * @return OfficeVenue[]
     */
    public function findAll(): array
    {
        if (!$this->tableReady()) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM office_venues ORDER BY is_active DESC, name ASC'
        );
        return array_map(static fn (array $r) => OfficeVenue::fromRow($r), $rows);
    }

    /**
     * Active venue names for property form datalists.
     *
     * @return list<string>
     */
    public function namesForSelect(): array
    {
        $names = [];
        foreach ($this->findActive() as $venue) {
            $names[] = $venue->name;
        }
        return $names;
    }

    public function create(
        string $name,
        ?string $addressLine1,
        ?string $city,
        ?string $stateRegion,
        ?string $postalCode,
        ?string $country,
        ?string $notes,
        ?float $latitude,
        ?float $longitude,
        ?int $actorUserId = null,
    ): OfficeVenue {
        if (!$this->tableReady()) {
            throw new \RuntimeException('office_venues table missing; run php scripts/migrate.php');
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Office / venue name is required.');
        }
        if (strlen($name) > 150) {
            throw new \InvalidArgumentException('Name must be 150 characters or fewer.');
        }

        $existing = $this->db->fetchOne(
            'SELECT * FROM office_venues WHERE LOWER(name) = LOWER(:n) LIMIT 1',
            ['n' => $name]
        );
        if ($existing !== null) {
            throw new \InvalidArgumentException("Office / venue '{$name}' already exists.");
        }

        $country = ($country !== null && trim($country) !== '') ? trim($country) : 'USA';

        $this->db->execute(
            'INSERT INTO office_venues
                (name, address_line1, city, state_region, postal_code, country, latitude, longitude, notes, is_active)
             VALUES
                (:name, :a1, :city, :state, :postal, :country, :lat, :lon, :notes, 1)',
            [
                'name' => $name,
                'a1' => $this->nullable($addressLine1),
                'city' => $this->nullable($city),
                'state' => $this->nullable($stateRegion),
                'postal' => $this->nullable($postalCode),
                'country' => $country,
                'lat' => $latitude,
                'lon' => $longitude,
                'notes' => $this->nullable($notes),
            ]
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'office_venues', $id, ['name' => $name]);
        $this->logger->info('Office venue created', ['id' => $id, 'name' => $name]);

        $venue = $this->find($id);
        if ($venue === null) {
            throw new \RuntimeException('Office venue insert succeeded but row could not be re-read.');
        }
        return $venue;
    }

    public function update(
        int $id,
        string $name,
        ?string $addressLine1,
        ?string $city,
        ?string $stateRegion,
        ?string $postalCode,
        ?string $country,
        ?string $notes,
        bool $isActive,
        ?float $latitude,
        ?float $longitude,
        ?int $actorUserId = null,
    ): OfficeVenue {
        if (!$this->tableReady()) {
            throw new \RuntimeException('office_venues table missing; run php scripts/migrate.php');
        }
        $existing = $this->find($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Office / venue not found.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Office / venue name is required.');
        }

        $dup = $this->db->fetchOne(
            'SELECT id FROM office_venues WHERE LOWER(name) = LOWER(:n) AND id != :id LIMIT 1',
            ['n' => $name, 'id' => $id]
        );
        if ($dup !== null) {
            throw new \InvalidArgumentException("Office / venue '{$name}' already exists.");
        }

        $country = ($country !== null && trim($country) !== '') ? trim($country) : 'USA';

        $this->db->execute(
            'UPDATE office_venues SET
                name = :name,
                address_line1 = :a1,
                city = :city,
                state_region = :state,
                postal_code = :postal,
                country = :country,
                latitude = :lat,
                longitude = :lon,
                notes = :notes,
                is_active = :active,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'name' => $name,
                'a1' => $this->nullable($addressLine1),
                'city' => $this->nullable($city),
                'state' => $this->nullable($stateRegion),
                'postal' => $this->nullable($postalCode),
                'country' => $country,
                'lat' => $latitude,
                'lon' => $longitude,
                'notes' => $this->nullable($notes),
                'active' => $isActive ? 1 : 0,
                'id' => $id,
            ]
        );
        $this->db->audit($actorUserId, 'update', 'office_venues', $id, ['name' => $name]);

        $venue = $this->find($id);
        if ($venue === null) {
            throw new \RuntimeException('Office venue update succeeded but row could not be re-read.');
        }
        return $venue;
    }

    public function updateCoordinates(int $id, float $latitude, float $longitude, ?int $actorUserId = null): void
    {
        if (!$this->tableReady()) {
            return;
        }
        $this->db->execute(
            'UPDATE office_venues SET latitude = :lat, longitude = :lon, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['lat' => $latitude, 'lon' => $longitude, 'id' => $id]
        );
        $this->db->audit($actorUserId, 'update_coordinates', 'office_venues', $id, [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    public function deactivate(int $id, ?int $actorUserId = null): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Office / venue not found.');
        }
        $this->db->execute(
            'UPDATE office_venues SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $id]
        );
        $this->db->audit($actorUserId, 'deactivate', 'office_venues', $id, ['name' => $existing->name]);
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
