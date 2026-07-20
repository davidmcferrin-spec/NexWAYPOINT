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

    /**
     * Distinct "City, State" locations for the user's properties (skips empty city).
     *
     * @return array<int, array{key: string, label: string, city: string, state_region: string}>
     */
    public function locationsForUser(int $userId): array
    {
        $byKey = [];
        foreach ($this->findForUser($userId) as $property) {
            $key = $property->locationKey();
            $label = $property->locationLabel();
            if ($key === null || $label === null) {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'city' => (string) $property->city,
                    'state_region' => (string) ($property->stateRegion ?? ''),
                ];
            }
        }
        $locations = array_values($byKey);
        usort($locations, static fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));
        return $locations;
    }

    /**
     * @return HotelProperty[]
     */
    public function findForUserAtLocation(int $userId, string $city, ?string $stateRegion): array
    {
        $sql = 'SELECT * FROM hotel_properties
                WHERE user_id = :user_id
                  AND LOWER(TRIM(COALESCE(city, \'\'))) = LOWER(:city)';
        $params = ['user_id' => $userId, 'city' => trim($city)];
        $state = trim((string) $stateRegion);
        if ($state !== '') {
            $sql .= ' AND LOWER(TRIM(COALESCE(state_region, \'\'))) = LOWER(:state)';
            $params['state'] = $state;
        } else {
            $sql .= ' AND (state_region IS NULL OR TRIM(state_region) = \'\')';
        }
        $sql .= ' ORDER BY hotel_name ASC';
        $rows = $this->db->fetchAll($sql, $params);
        return array_map(static fn (array $row) => HotelProperty::fromRow($row), $rows);
    }

    /**
     * Filter/sort the current user's properties for the directory UI.
     *
     * @param array{
     *   q?: string,
     *   city?: string,
     *   state_region?: string,
     *   destination_fee?: string,
     *   blacklisted?: string,
     *   teammate_adverse?: string
     * } $filters
     * @return HotelProperty[]
     */
    public function searchForUser(int $userId, array $filters = [], string $sort = 'hotel_name'): array
    {
        $allowedSort = [
            'hotel_name' => 'hotel_name ASC, city ASC',
            'city' => 'city ASC, hotel_name ASC',
            'overall_rating' => 'overall_rating DESC, hotel_name ASC',
            'updated' => 'updated_at DESC',
        ];
        $order = $allowedSort[$sort] ?? $allowedSort['hotel_name'];

        $sql = 'SELECT * FROM hotel_properties WHERE user_id = :user_id';
        $params = ['user_id' => $userId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (LOWER(hotel_name) LIKE LOWER(:q) OR LOWER(COALESCE(brand, \'\')) LIKE LOWER(:q))';
            $params['q'] = '%' . $q . '%';
        }
        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $sql .= ' AND LOWER(TRIM(COALESCE(city, \'\'))) = LOWER(:city)';
            $params['city'] = $city;
        }
        $state = trim((string) ($filters['state_region'] ?? ''));
        if ($state !== '') {
            $sql .= ' AND LOWER(TRIM(COALESCE(state_region, \'\'))) = LOWER(:state)';
            $params['state'] = $state;
        }
        $fee = (string) ($filters['destination_fee'] ?? '');
        if ($fee === '1') {
            $sql .= ' AND has_destination_fee = 1';
        } elseif ($fee === '0') {
            $sql .= ' AND has_destination_fee = 0';
        }
        $bl = (string) ($filters['blacklisted'] ?? '');
        if ($bl === '1') {
            $sql .= ' AND is_blacklisted = 1';
        } elseif ($bl === '0') {
            $sql .= ' AND is_blacklisted = 0';
        }

        $sql .= " ORDER BY {$order}";
        $rows = $this->db->fetchAll($sql, $params);
        $properties = array_map(static fn (array $row) => HotelProperty::fromRow($row), $rows);

        if (($filters['teammate_adverse'] ?? '') === '1') {
            $properties = array_values(array_filter(
                $properties,
                fn (HotelProperty $p) => $this->findTeammateAdversePreferences($userId, $p->hotelName, $p->city) !== []
            ));
        }

        return $properties;
    }

    /**
     * Other users' blacklists that match this hotel name (+ optional city).
     * Blacklist remains owned by each user; this only exposes the adverse preference.
     *
     * @return array<int, array{user_id: int, display_name: string, hotel_name: string, city: ?string, reason: ?string, property_id: int}>
     */
    public function findTeammateAdversePreferences(int $viewerUserId, string $hotelName, ?string $city): array
    {
        $sql = 'SELECT hp.id AS property_id, hp.user_id, hp.hotel_name, hp.city, hp.blacklist_reason, u.display_name
                FROM hotel_properties hp
                INNER JOIN users u ON u.id = hp.user_id
                WHERE hp.user_id != :viewer
                  AND hp.is_blacklisted = 1
                  AND u.is_active = 1
                  AND LOWER(hp.hotel_name) = LOWER(:hotel_name)';
        $params = ['viewer' => $viewerUserId, 'hotel_name' => trim($hotelName)];
        if ($city !== null && trim($city) !== '') {
            $sql .= ' AND LOWER(COALESCE(hp.city, \'\')) = LOWER(:city)';
            $params['city'] = trim($city);
        }
        $sql .= ' ORDER BY u.display_name ASC';

        $rows = $this->db->fetchAll($sql, $params);
        return array_map(static fn (array $row) => [
            'user_id' => (int) $row['user_id'],
            'display_name' => (string) $row['display_name'],
            'hotel_name' => (string) $row['hotel_name'],
            'city' => $row['city'] ?? null,
            'reason' => $row['blacklist_reason'] ?? null,
            'property_id' => (int) $row['property_id'],
        ], $rows);
    }

    /**
     * Teammate blacklists in the same City, State (location-level adverse signal).
     *
     * @return array<int, array{user_id: int, display_name: string, hotel_name: string, city: ?string, reason: ?string, property_id: int}>
     */
    public function findTeammateAdverseAtLocation(int $viewerUserId, string $city, ?string $stateRegion): array
    {
        $city = trim($city);
        if ($city === '') {
            return [];
        }
        $sql = 'SELECT hp.id AS property_id, hp.user_id, hp.hotel_name, hp.city, hp.blacklist_reason, u.display_name
                FROM hotel_properties hp
                INNER JOIN users u ON u.id = hp.user_id
                WHERE hp.user_id != :viewer
                  AND hp.is_blacklisted = 1
                  AND u.is_active = 1
                  AND LOWER(TRIM(COALESCE(hp.city, \'\'))) = LOWER(:city)';
        $params = ['viewer' => $viewerUserId, 'city' => $city];
        $state = trim((string) $stateRegion);
        if ($state !== '') {
            $sql .= ' AND LOWER(TRIM(COALESCE(hp.state_region, \'\'))) = LOWER(:state)';
            $params['state'] = $state;
        }
        $sql .= ' ORDER BY u.display_name ASC, hp.hotel_name ASC';

        $rows = $this->db->fetchAll($sql, $params);
        return array_map(static fn (array $row) => [
            'user_id' => (int) $row['user_id'],
            'display_name' => (string) $row['display_name'],
            'hotel_name' => (string) $row['hotel_name'],
            'city' => $row['city'] ?? null,
            'reason' => $row['blacklist_reason'] ?? null,
            'property_id' => (int) $row['property_id'],
        ], $rows);
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
            'walk_to_office', 'has_destination_fee', 'is_blacklisted',
        ] as $boolField) {
            $data[$boolField] = !empty($data[$boolField]) ? 1 : 0;
        }
        return $data;
    }
}
