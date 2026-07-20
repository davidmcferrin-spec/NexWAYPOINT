<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

final class HotelPropertyRepository
{
    public const BED_TYPES = ['king', 'queen', 'dual_queen'];
    public const BATHROOM_TYPES = ['tub', 'walk_in_shower'];

    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function find(int $id): ?HotelProperty
    {
        $row = $this->db->fetchOne('SELECT * FROM hotel_properties WHERE id = :id', ['id' => $id]);
        return $row === null ? null : HotelProperty::fromRow($row);
    }

    /**
     * @return HotelProperty[]
     */
    public function findForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_properties WHERE user_id = :user_id ORDER BY hotel_name ASC, city ASC',
            ['user_id' => $userId]
        );
        return array_map(static fn (array $row) => HotelProperty::fromRow($row), $rows);
    }

    /**
     * @return HotelProperty[]
     */
    public function findBlacklistedForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_properties WHERE user_id = :user_id AND is_blacklisted = 1 ORDER BY updated_at DESC',
            ['user_id' => $userId]
        );
        return array_map(static fn (array $row) => HotelProperty::fromRow($row), $rows);
    }

    public function findMatchingBlacklist(int $userId, string $hotelName, ?string $city): ?HotelProperty
    {
        $sql = 'SELECT * FROM hotel_properties
                WHERE user_id = :user_id
                  AND is_blacklisted = 1
                  AND LOWER(hotel_name) = LOWER(:hotel_name)';
        $params = ['user_id' => $userId, 'hotel_name' => $hotelName];

        if ($city !== null && $city !== '') {
            $sql .= ' AND LOWER(COALESCE(city, \'\')) = LOWER(:city)';
            $params['city'] = $city;
        }

        $row = $this->db->fetchOne($sql . ' LIMIT 1', $params);
        return $row === null ? null : HotelProperty::fromRow($row);
    }

    public function findByNameCity(int $userId, string $hotelName, ?string $city): ?HotelProperty
    {
        $sql = 'SELECT * FROM hotel_properties
                WHERE user_id = :user_id AND LOWER(hotel_name) = LOWER(:hotel_name)';
        $params = ['user_id' => $userId, 'hotel_name' => $hotelName];
        if ($city !== null && $city !== '') {
            $sql .= ' AND LOWER(COALESCE(city, \'\')) = LOWER(:city)';
            $params['city'] = $city;
        } else {
            $sql .= ' AND (city IS NULL OR city = \'\')';
        }
        $row = $this->db->fetchOne($sql . ' ORDER BY id ASC LIMIT 1', $params);
        return $row === null ? null : HotelProperty::fromRow($row);
    }

    public function create(HotelProperty $property, ?int $actorUserId = null): HotelProperty
    {
        $this->validate($property);
        $data = $property->toArray();
        unset($data['id']);
        $params = $this->coerceForDb($data);
        $columns = array_keys($params);
        $placeholders = array_map(static fn (string $c) => ":{$c}", $columns);

        $this->db->execute(
            sprintf(
                'INSERT INTO hotel_properties (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            ),
            $params
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'hotel_properties', $id, ['hotel_name' => $property->hotelName]);
        $this->logger->info('Hotel property created', ['id' => $id, 'user_id' => $property->userId]);

        $created = $this->find($id);
        if ($created === null) {
            throw new \RuntimeException('Hotel property insert succeeded but row could not be re-read.');
        }
        return $created;
    }

    public function update(HotelProperty $property, ?int $actorUserId = null): HotelProperty
    {
        if ($property->id === null) {
            throw new \InvalidArgumentException('Cannot update a HotelProperty without an id.');
        }
        $this->validate($property);
        $data = $property->toArray();
        $id = $data['id'];
        unset($data['id']);
        $params = $this->coerceForDb($data);
        $assignments = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($params)));
        $params['id'] = $id;

        $this->db->execute(
            "UPDATE hotel_properties SET {$assignments}, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            $params
        );
        $this->db->audit($actorUserId, 'update', 'hotel_properties', $id, ['hotel_name' => $property->hotelName]);

        $updated = $this->find($id);
        if ($updated === null) {
            throw new \RuntimeException('Hotel property update succeeded but row could not be re-read.');
        }
        return $updated;
    }

    public function recomputeOverallRating(int $propertyId): void
    {
        $row = $this->db->fetchOne(
            'SELECT AVG(stay_rating) AS avg_rating FROM hotel_stays
             WHERE hotel_property_id = :id AND stay_rating IS NOT NULL',
            ['id' => $propertyId]
        );
        $avg = $row['avg_rating'] ?? null;
        $this->db->execute(
            'UPDATE hotel_properties SET overall_rating = :r, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            [
                'r' => $avg === null ? null : round((float) $avg, 2),
                'id' => $propertyId,
            ]
        );
    }

    private function validate(HotelProperty $property): void
    {
        $errors = [];
        if (trim($property->hotelName) === '') {
            $errors[] = 'hotel_name is required.';
        }
        if ($property->wifiQuality !== null && ($property->wifiQuality < 1 || $property->wifiQuality > 5)) {
            $errors[] = 'wifi_quality must be between 1 and 5.';
        }
        if ($property->noiseLevel !== null && ($property->noiseLevel < 1 || $property->noiseLevel > 5)) {
            $errors[] = 'noise_level must be between 1 and 5.';
        }
        if ($property->isBlacklisted && ($property->blacklistReason === null || trim($property->blacklistReason) === '')) {
            $errors[] = 'blacklist_reason is required when is_blacklisted is true.';
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException('Hotel property validation failed: ' . implode(' ', $errors));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function coerceForDb(array $data): array
    {
        foreach ([
            'has_desk', 'has_pool', 'has_hot_tub', 'has_breakfast', 'has_gym', 'has_free_parking',
            'has_airport_shuttle', 'has_ev_charging', 'has_onsite_restaurant', 'has_offsite_gym',
            'walk_to_office', 'is_blacklisted',
        ] as $boolField) {
            $data[$boolField] = !empty($data[$boolField]) ? 1 : 0;
        }
        return $data;
    }
}
