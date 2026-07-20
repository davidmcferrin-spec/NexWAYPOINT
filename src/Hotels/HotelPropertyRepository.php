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
    public function findAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_properties ORDER BY hotel_name ASC, city ASC'
        );
        return array_map(static fn (array $row) => HotelProperty::fromRow($row), $rows);
    }

    /**
     * @deprecated Use findAll() — properties are site-wide.
     * @return HotelProperty[]
     */
    public function findForUser(int $userId): array
    {
        unset($userId);
        return $this->findAll();
    }

    /**
     * @return HotelProperty[]
     */
    public function findBlacklistedForUser(int $userId): array
    {
        if (!$this->db->tableExists('user_hotel_blacklist')) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT hp.* FROM hotel_properties hp
             INNER JOIN user_hotel_blacklist b ON b.hotel_property_id = hp.id
             WHERE b.user_id = :user_id
             ORDER BY b.updated_at DESC',
            ['user_id' => $userId]
        );
        return array_map(static fn (array $row) => HotelProperty::fromRow($row), $rows);
    }

    public function findMatchingBlacklist(int $userId, string $hotelName, ?string $city): ?HotelProperty
    {
        $bl = new UserHotelBlacklistRepository($this->db, $this->logger);
        $match = $bl->findMatchingForUser($userId, $hotelName, $city);
        if ($match === null) {
            return null;
        }
        return $this->find((int) $match['property_id']);
    }

    /**
     * Global find by name + city (+ optional state). Identity is case-insensitive.
     */
    public function findByNameCity(string $hotelName, ?string $city, ?string $stateRegion = null): ?HotelProperty
    {
        $sql = 'SELECT * FROM hotel_properties
                WHERE LOWER(hotel_name) = LOWER(:hotel_name)
                  AND LOWER(COALESCE(city, \'\')) = LOWER(:city)';
        $params = [
            'hotel_name' => trim($hotelName),
            'city' => trim((string) $city),
        ];
        if ($stateRegion !== null) {
            $sql .= ' AND LOWER(COALESCE(state_region, \'\')) = LOWER(:state)';
            $params['state'] = trim($stateRegion);
        }
        $row = $this->db->fetchOne($sql . ' ORDER BY id ASC LIMIT 1', $params);
        return $row === null ? null : HotelProperty::fromRow($row);
    }

    /**
     * @deprecated Signature kept for callers that still pass userId; ignored.
     */
    public function findByNameCityForUser(int $userId, string $hotelName, ?string $city): ?HotelProperty
    {
        unset($userId);
        return $this->findByNameCity($hotelName, $city);
    }

    /**
     * Distinct "City, State" locations across the directory.
     *
     * @return array<int, array{key: string, label: string, city: string, state_region: string}>
     */
    public function locations(): array
    {
        $byKey = [];
        foreach ($this->findAll() as $property) {
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
     * @deprecated Use locations()
     * @return array<int, array{key: string, label: string, city: string, state_region: string}>
     */
    public function locationsForUser(int $userId): array
    {
        unset($userId);
        return $this->locations();
    }

    /**
     * @return list<string>
     */
    public function walkToOfficeVenues(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT TRIM(walk_to_office_notes) AS venue
             FROM hotel_properties
             WHERE walk_to_office_notes IS NOT NULL
               AND TRIM(walk_to_office_notes) != ''
             ORDER BY venue ASC"
        );
        $venues = [];
        foreach ($rows as $row) {
            $venue = trim((string) ($row['venue'] ?? ''));
            if ($venue !== '') {
                $venues[] = $venue;
            }
        }
        return $venues;
    }

    /**
     * @deprecated Use walkToOfficeVenues()
     * @return list<string>
     */
    public function walkToOfficeVenuesForUser(int $userId): array
    {
        unset($userId);
        return $this->walkToOfficeVenues();
    }

    /**
     * @return HotelProperty[]
     */
    public function findAtLocation(string $city, ?string $stateRegion): array
    {
        $sql = 'SELECT * FROM hotel_properties
                WHERE LOWER(TRIM(COALESCE(city, \'\'))) = LOWER(:city)';
        $params = ['city' => trim($city)];
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
     * @deprecated Use findAtLocation()
     * @return HotelProperty[]
     */
    public function findForUserAtLocation(int $userId, string $city, ?string $stateRegion): array
    {
        unset($userId);
        return $this->findAtLocation($city, $stateRegion);
    }

    /**
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
    public function search(int $viewerUserId, array $filters = [], string $sort = 'hotel_name'): array
    {
        $allowedSort = [
            'hotel_name' => 'hp.hotel_name ASC, hp.city ASC',
            'city' => 'hp.city ASC, hp.hotel_name ASC',
            'overall_rating' => 'hp.overall_rating DESC, hp.hotel_name ASC',
            'updated' => 'hp.updated_at DESC',
        ];
        $order = $allowedSort[$sort] ?? $allowedSort['hotel_name'];

        $sql = 'SELECT hp.* FROM hotel_properties hp WHERE 1=1';
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (LOWER(hp.hotel_name) LIKE LOWER(:q) OR LOWER(COALESCE(hp.brand, \'\')) LIKE LOWER(:q))';
            $params['q'] = '%' . $q . '%';
        }
        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $sql .= ' AND LOWER(TRIM(COALESCE(hp.city, \'\'))) = LOWER(:city)';
            $params['city'] = $city;
        }
        $state = trim((string) ($filters['state_region'] ?? ''));
        if ($state !== '') {
            $sql .= ' AND LOWER(TRIM(COALESCE(hp.state_region, \'\'))) = LOWER(:state)';
            $params['state'] = $state;
        }
        $fee = (string) ($filters['destination_fee'] ?? '');
        if ($fee === '1') {
            $sql .= ' AND hp.has_destination_fee = 1';
        } elseif ($fee === '0') {
            $sql .= ' AND hp.has_destination_fee = 0';
        }

        $bl = (string) ($filters['blacklisted'] ?? '');
        if ($bl === '1' || $bl === '0') {
            if ($this->db->tableExists('user_hotel_blacklist')) {
                if ($bl === '1') {
                    $sql .= ' AND EXISTS (
                        SELECT 1 FROM user_hotel_blacklist b
                        WHERE b.hotel_property_id = hp.id AND b.user_id = :viewer_bl
                    )';
                } else {
                    $sql .= ' AND NOT EXISTS (
                        SELECT 1 FROM user_hotel_blacklist b
                        WHERE b.hotel_property_id = hp.id AND b.user_id = :viewer_bl
                    )';
                }
                $params['viewer_bl'] = $viewerUserId;
            }
        }

        $sql .= " ORDER BY {$order}";
        $rows = $this->db->fetchAll($sql, $params);
        $properties = array_map(static fn (array $row) => HotelProperty::fromRow($row), $rows);

        if (($filters['teammate_adverse'] ?? '') === '1') {
            $properties = array_values(array_filter(
                $properties,
                function (HotelProperty $p) use ($viewerUserId): bool {
                    if ($p->id === null) {
                        return false;
                    }
                    return $this->findTeammateAdverseForProperty($viewerUserId, $p->id) !== [];
                }
            ));
        }

        return $properties;
    }

    /**
     * @deprecated Use search()
     * @return HotelProperty[]
     */
    public function searchForUser(int $userId, array $filters = [], string $sort = 'hotel_name'): array
    {
        return $this->search($userId, $filters, $sort);
    }

    /**
     * @return array<int, array{user_id: int, display_name: string, hotel_name: string, city: ?string, reason: ?string, property_id: int}>
     */
    public function findTeammateAdverseForProperty(int $viewerUserId, int $propertyId): array
    {
        if (!$this->db->tableExists('user_hotel_blacklist')) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT b.user_id, b.reason, hp.id AS property_id, hp.hotel_name, hp.city, u.display_name
             FROM user_hotel_blacklist b
             INNER JOIN hotel_properties hp ON hp.id = b.hotel_property_id
             INNER JOIN users u ON u.id = b.user_id
             WHERE b.hotel_property_id = :pid
               AND b.user_id != :viewer
               AND u.is_active = 1
             ORDER BY u.display_name ASC',
            ['pid' => $propertyId, 'viewer' => $viewerUserId]
        );
        return array_map(static fn (array $row) => [
            'user_id' => (int) $row['user_id'],
            'display_name' => (string) $row['display_name'],
            'hotel_name' => (string) $row['hotel_name'],
            'city' => $row['city'] ?? null,
            'reason' => $row['reason'] ?? null,
            'property_id' => (int) $row['property_id'],
        ], $rows);
    }

    /**
     * @return array<int, array{user_id: int, display_name: string, hotel_name: string, city: ?string, reason: ?string, property_id: int}>
     */
    public function findTeammateAdversePreferences(int $viewerUserId, string $hotelName, ?string $city): array
    {
        if (!$this->db->tableExists('user_hotel_blacklist')) {
            return [];
        }
        $sql = 'SELECT b.user_id, b.reason, hp.id AS property_id, hp.hotel_name, hp.city, u.display_name
                FROM user_hotel_blacklist b
                INNER JOIN hotel_properties hp ON hp.id = b.hotel_property_id
                INNER JOIN users u ON u.id = b.user_id
                WHERE b.user_id != :viewer
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
            'reason' => $row['reason'] ?? null,
            'property_id' => (int) $row['property_id'],
        ], $rows);
    }

    /**
     * @return array<int, array{user_id: int, display_name: string, hotel_name: string, city: ?string, reason: ?string, property_id: int}>
     */
    public function findTeammateAdverseAtLocation(int $viewerUserId, string $city, ?string $stateRegion): array
    {
        $city = trim($city);
        if ($city === '' || !$this->db->tableExists('user_hotel_blacklist')) {
            return [];
        }
        $sql = 'SELECT b.user_id, b.reason, hp.id AS property_id, hp.hotel_name, hp.city, u.display_name
                FROM user_hotel_blacklist b
                INNER JOIN hotel_properties hp ON hp.id = b.hotel_property_id
                INNER JOIN users u ON u.id = b.user_id
                WHERE b.user_id != :viewer
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
            'reason' => $row['reason'] ?? null,
            'property_id' => (int) $row['property_id'],
        ], $rows);
    }

    public function create(HotelProperty $property, ?int $actorUserId = null): HotelProperty
    {
        $this->validate($property);
        $data = $property->toArray();
        unset($data['id'], $data['overall_rating']);
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
        $this->logger->info('Hotel property created', [
            'id' => $id,
            'created_by_user_id' => $property->createdByUserId,
        ]);

        $created = $this->find($id);
        if ($created === null) {
            throw new \RuntimeException('Hotel property insert succeeded but row could not be re-read.');
        }
        return $created;
    }

    /**
     * Find global property by name+city+state or create it.
     */
    public function findOrCreate(
        string $hotelName,
        ?string $city,
        ?string $stateRegion,
        ?int $createdByUserId,
        ?int $actorUserId = null,
        ?string $brand = null,
        ?string $addressLine1 = null,
        ?string $phone = null,
    ): HotelProperty {
        $existing = $this->findByNameCity($hotelName, $city, $stateRegion);
        if ($existing !== null) {
            return $existing;
        }
        return $this->create(new HotelProperty(
            id: null,
            createdByUserId: $createdByUserId,
            hotelName: $hotelName,
            brand: $brand,
            addressLine1: $addressLine1,
            addressLine2: null,
            city: $city,
            stateRegion: $stateRegion,
            postalCode: null,
            country: null,
            phone: $phone,
            latitude: null,
            longitude: null,
            hasDesk: false,
            deskNotes: null,
            hasPool: false,
            hasHotTub: false,
            hasBreakfast: false,
            breakfastNotes: null,
            hasGym: false,
            hasFreeParking: false,
            hasAirportShuttle: false,
            hasEvCharging: false,
            hasOnsiteRestaurant: false,
            hasOffsiteGym: false,
            walkToOffice: false,
            walkToOfficeNotes: null,
            hasDestinationFee: false,
            destinationFeeNotes: null,
            wifiQuality: null,
            noiseLevel: null,
            uniqueFeatures: null,
            overallRating: null,
        ), $actorUserId ?? $createdByUserId);
    }

    public function update(HotelProperty $property, ?int $actorUserId = null): HotelProperty
    {
        if ($property->id === null) {
            throw new \InvalidArgumentException('Cannot update a HotelProperty without an id.');
        }
        $this->validate($property);
        $data = $property->toArray();
        $id = $data['id'];
        unset($data['id'], $data['overall_rating']);
        // Preserve creator unless explicitly set.
        if ($data['created_by_user_id'] === null) {
            unset($data['created_by_user_id']);
        }
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

    public function updateCoordinates(int $propertyId, float $latitude, float $longitude, ?int $actorUserId = null): void
    {
        $this->db->execute(
            'UPDATE hotel_properties SET latitude = :lat, longitude = :lon, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['lat' => $latitude, 'lon' => $longitude, 'id' => $propertyId]
        );
        $this->db->audit($actorUserId, 'update_coordinates', 'hotel_properties', $propertyId, [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    public function countStays(int $propertyId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM hotel_stays WHERE hotel_property_id = :id',
            ['id' => $propertyId]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    public function delete(int $id, ?int $actorUserId = null): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return;
        }

        if ($this->db->tableExists('user_hotel_blacklist')) {
            $this->db->execute(
                'DELETE FROM user_hotel_blacklist WHERE hotel_property_id = :id',
                ['id' => $id]
            );
        }

        $stayIds = $this->db->fetchAll(
            'SELECT id FROM hotel_stays WHERE hotel_property_id = :id',
            ['id' => $id]
        );
        $ids = array_map(static fn (array $row) => (int) $row['id'], $stayIds);

        if ($ids !== []) {
            $placeholders = implode(', ', array_map(static fn (int $i) => ':s' . $i, array_keys($ids)));
            $params = [];
            foreach ($ids as $i => $stayId) {
                $params['s' . $i] = $stayId;
            }

            $this->db->execute(
                "UPDATE trip_segments SET hotel_stay_id = NULL WHERE hotel_stay_id IN ({$placeholders})",
                $params
            );
            $this->db->execute(
                "DELETE FROM hotel_photos WHERE hotel_stay_id IN ({$placeholders})",
                $params
            );
            $this->db->execute(
                'DELETE FROM hotel_stays WHERE hotel_property_id = :id',
                ['id' => $id]
            );
        }

        $this->db->execute('DELETE FROM hotel_properties WHERE id = :id', ['id' => $id]);
        $this->db->audit($actorUserId, 'delete', 'hotel_properties', $id, [
            'hotel_name' => $existing->hotelName,
            'stays_deleted' => count($ids),
        ]);
        $this->logger->info('Hotel property deleted', [
            'id' => $id,
            'hotel_name' => $existing->hotelName,
            'stays_deleted' => count($ids),
        ]);
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
            'walk_to_office', 'has_destination_fee',
        ] as $boolField) {
            if (array_key_exists($boolField, $data)) {
                $data[$boolField] = !empty($data[$boolField]) ? 1 : 0;
            }
        }
        if (array_key_exists('city', $data) && ($data['city'] === null || $data['city'] === '')) {
            $data['city'] = '';
        }
        if (array_key_exists('state_region', $data) && ($data['state_region'] === null || $data['state_region'] === '')) {
            $data['state_region'] = '';
        }
        return $data;
    }
}
