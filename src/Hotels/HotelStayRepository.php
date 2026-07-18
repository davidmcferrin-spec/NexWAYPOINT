<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * CRUD access for hotel_stays (+ hotel_photos). All writes are validated
 * before hitting the DB and are recorded to audit_log via Database::audit().
 */
final class HotelStayRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function find(int $id): ?HotelStay
    {
        $row = $this->db->fetchOne('SELECT * FROM hotel_stays WHERE id = :id', ['id' => $id]);
        return $row === null ? null : HotelStay::fromRow($row);
    }

    /**
     * @return HotelStay[]
     */
    public function findForUser(int $userId, ?string $orderBy = 'stay_start DESC'): array
    {
        $allowedOrder = ['stay_start DESC', 'stay_start ASC', 'rating DESC', 'hotel_name ASC'];
        $order = in_array($orderBy, $allowedOrder, true) ? $orderBy : 'stay_start DESC';

        $rows = $this->db->fetchAll(
            "SELECT * FROM hotel_stays WHERE user_id = :user_id ORDER BY {$order}",
            ['user_id' => $userId]
        );
        return array_map(static fn (array $row) => HotelStay::fromRow($row), $rows);
    }

    /**
     * @return HotelStay[]
     */
    public function findBlacklistedForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_stays WHERE user_id = :user_id AND is_blacklisted = 1 ORDER BY updated_at DESC',
            ['user_id' => $userId]
        );
        return array_map(static fn (array $row) => HotelStay::fromRow($row), $rows);
    }

    /**
     * Case-insensitive lookup used to warn a user before they book somewhere
     * they've already blacklisted, keyed on hotel name + city.
     */
    public function findMatchingBlacklist(int $userId, string $hotelName, ?string $city): ?HotelStay
    {
        $sql = 'SELECT * FROM hotel_stays
                WHERE user_id = :user_id
                  AND is_blacklisted = 1
                  AND LOWER(hotel_name) = LOWER(:hotel_name)';
        $params = ['user_id' => $userId, 'hotel_name' => $hotelName];

        if ($city !== null && $city !== '') {
            $sql .= ' AND LOWER(COALESCE(city, \'\')) = LOWER(:city)';
            $params['city'] = $city;
        }

        $row = $this->db->fetchOne($sql . ' LIMIT 1', $params);
        return $row === null ? null : HotelStay::fromRow($row);
    }

    /**
     * @throws \InvalidArgumentException on validation failure
     */
    public function create(HotelStay $stay, ?int $actorUserId = null): HotelStay
    {
        $this->validate($stay);

        $data = $stay->toArray();
        unset($data['id']);

        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c) => ":{$c}", $columns);
        $params = $this->coerceForDb($data);

        $sql = sprintf(
            'INSERT INTO hotel_stays (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->db->execute($sql, $params);
        $newId = $this->db->lastInsertId();

        $this->db->audit($actorUserId, 'create', 'hotel_stays', $newId, ['hotel_name' => $stay->hotelName]);
        $this->logger->info('Hotel stay created', ['id' => $newId, 'user_id' => $stay->userId, 'hotel_name' => $stay->hotelName]);

        $created = $this->find($newId);
        if ($created === null) {
            throw new \RuntimeException('Hotel stay insert succeeded but row could not be re-read.');
        }
        return $created;
    }

    /**
     * @throws \InvalidArgumentException on validation failure
     */
    public function update(HotelStay $stay, ?int $actorUserId = null): HotelStay
    {
        if ($stay->id === null) {
            throw new \InvalidArgumentException('Cannot update a HotelStay without an id.');
        }
        $this->validate($stay);

        $data = $stay->toArray();
        $id = $data['id'];
        unset($data['id']);

        $assignments = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($data)));
        $params = $this->coerceForDb($data);
        $params['id'] = $id;

        $this->db->execute("UPDATE hotel_stays SET {$assignments}, updated_at = CURRENT_TIMESTAMP WHERE id = :id", $params);

        $this->db->audit($actorUserId, 'update', 'hotel_stays', $id, ['hotel_name' => $stay->hotelName]);
        $this->logger->info('Hotel stay updated', ['id' => $id, 'user_id' => $stay->userId]);

        $updated = $this->find($id);
        if ($updated === null) {
            throw new \RuntimeException('Hotel stay update succeeded but row could not be re-read.');
        }
        return $updated;
    }

    public function delete(int $id, ?int $actorUserId = null): void
    {
        $this->db->execute('DELETE FROM hotel_stays WHERE id = :id', ['id' => $id]);
        $this->db->audit($actorUserId, 'delete', 'hotel_stays', $id, []);
        $this->logger->info('Hotel stay deleted', ['id' => $id]);
    }

    public function addPhoto(int $hotelStayId, string $filePath, ?string $caption, ?int $actorUserId = null): int
    {
        $this->db->execute(
            'INSERT INTO hotel_photos (hotel_stay_id, file_path, caption) VALUES (:stay_id, :file_path, :caption)',
            ['stay_id' => $hotelStayId, 'file_path' => $filePath, 'caption' => $caption]
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'hotel_photos', $id, ['hotel_stay_id' => $hotelStayId]);
        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function photosFor(int $hotelStayId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM hotel_photos WHERE hotel_stay_id = :stay_id ORDER BY uploaded_at DESC',
            ['stay_id' => $hotelStayId]
        );
    }

    private function validate(HotelStay $stay): void
    {
        $errors = [];

        if (trim($stay->hotelName) === '') {
            $errors[] = 'hotel_name is required.';
        }
        if (!$this->isValidDate($stay->stayStart)) {
            $errors[] = 'stay_start must be a valid Y-m-d date.';
        }
        if (!$this->isValidDate($stay->stayEnd)) {
            $errors[] = 'stay_end must be a valid Y-m-d date.';
        }
        if ($this->isValidDate($stay->stayStart) && $this->isValidDate($stay->stayEnd) && $stay->stayEnd < $stay->stayStart) {
            $errors[] = 'stay_end cannot be before stay_start.';
        }
        if ($stay->rating !== null && ($stay->rating < 1 || $stay->rating > 5)) {
            $errors[] = 'rating must be between 1 and 5.';
        }
        if ($stay->wifiQuality !== null && ($stay->wifiQuality < 1 || $stay->wifiQuality > 5)) {
            $errors[] = 'wifi_quality must be between 1 and 5.';
        }
        if ($stay->noiseLevel !== null && ($stay->noiseLevel < 1 || $stay->noiseLevel > 5)) {
            $errors[] = 'noise_level must be between 1 and 5.';
        }
        if ($stay->isBlacklisted && ($stay->blacklistReason === null || trim($stay->blacklistReason) === '')) {
            $errors[] = 'blacklist_reason is required when is_blacklisted is true.';
        }
        if (strlen($stay->currency) !== 3) {
            $errors[] = 'currency must be a 3-letter ISO code.';
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException('Hotel stay validation failed: ' . implode(' ', $errors));
        }
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function coerceForDb(array $data): array
    {
        foreach (['has_desk', 'has_pool', 'has_hot_tub', 'has_breakfast', 'has_gym', 'has_free_parking', 'has_airport_shuttle', 'is_blacklisted'] as $boolField) {
            $data[$boolField] = $data[$boolField] ? 1 : 0;
        }
        if (array_key_exists('would_return', $data)) {
            $data['would_return'] = $data['would_return'] === null ? null : ($data['would_return'] ? 1 : 0);
        }
        return $data;
    }
}
