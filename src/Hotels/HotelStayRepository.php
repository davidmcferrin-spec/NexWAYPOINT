<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * CRUD for hotel_stays (+ hotel_photos). Stay writes recompute the linked
 * property's overall_rating from stay_rating averages.
 */
final class HotelStayRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
        private readonly ?HotelPropertyRepository $properties = null,
    ) {
    }

    private function properties(): HotelPropertyRepository
    {
        return $this->properties ?? new HotelPropertyRepository($this->db, $this->logger);
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
        $allowedOrder = ['stay_start DESC', 'stay_start ASC', 'stay_rating DESC'];
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
    public function findForProperty(int $propertyId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_stays WHERE hotel_property_id = :pid ORDER BY stay_start DESC',
            ['pid' => $propertyId]
        );
        return array_map(static fn (array $row) => HotelStay::fromRow($row), $rows);
    }

    public function findByConfirmationCode(int $userId, string $confirmationCode): ?HotelStay
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM hotel_stays
             WHERE user_id = :user_id AND UPPER(confirmation_code) = UPPER(:code)
             ORDER BY id DESC LIMIT 1',
            ['user_id' => $userId, 'code' => trim($confirmationCode)]
        );
        return $row === null ? null : HotelStay::fromRow($row);
    }

    /**
     * Match a cancel notice that may only have hotel name + dates (Hilton).
     */
    public function findForCancelMatch(
        int $userId,
        ?string $propertyName,
        ?string $checkIn,
        ?string $checkOut
    ): ?HotelStay {
        if ($propertyName !== null && $checkIn !== null) {
            $rows = $this->db->fetchAll(
                'SELECT hs.* FROM hotel_stays hs
                 INNER JOIN hotel_properties hp ON hp.id = hs.hotel_property_id
                 WHERE hs.user_id = :user_id
                   AND hs.stay_start = :start
                   AND LOWER(hp.hotel_name) LIKE LOWER(:name)
                 ORDER BY hs.id DESC LIMIT 5',
                [
                    'user_id' => $userId,
                    'start' => $checkIn,
                    'name' => '%' . trim($propertyName) . '%',
                ]
            );
            if ($rows !== []) {
                if ($checkOut !== null) {
                    foreach ($rows as $row) {
                        if (($row['stay_end'] ?? null) === $checkOut) {
                            return HotelStay::fromRow($row);
                        }
                    }
                }
                return HotelStay::fromRow($rows[0]);
            }
        }
        return null;
    }

    public function create(HotelStay $stay, ?int $actorUserId = null): HotelStay
    {
        $this->validate($stay);

        $data = $stay->toArray();
        unset($data['id']);
        $params = $this->coerceForDb($data);
        $columns = array_keys($params);
        $placeholders = array_map(static fn (string $c) => ":{$c}", $columns);

        $this->db->execute(
            sprintf(
                'INSERT INTO hotel_stays (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            ),
            $params
        );
        $newId = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'hotel_stays', $newId, [
            'hotel_property_id' => $stay->hotelPropertyId,
        ]);
        $this->logger->info('Hotel stay created', [
            'id' => $newId,
            'user_id' => $stay->userId,
            'hotel_property_id' => $stay->hotelPropertyId,
        ]);

        $this->properties()->recomputeOverallRating($stay->hotelPropertyId);

        $created = $this->find($newId);
        if ($created === null) {
            throw new \RuntimeException('Hotel stay insert succeeded but row could not be re-read.');
        }
        return $created;
    }

    /**
     * Insert or update a stay keyed by confirmation_code when present.
     *
     * @return array{stay: HotelStay, created: bool}
     */
    public function upsertFromImport(HotelStay $stay, ?int $actorUserId = null): array
    {
        if ($stay->confirmationCode !== null && trim($stay->confirmationCode) !== '') {
            $existing = $this->findByConfirmationCode($stay->userId, $stay->confirmationCode);
            if ($existing !== null) {
                $updated = $this->update(new HotelStay(
                    id: $existing->id,
                    userId: $existing->userId,
                    hotelPropertyId: $stay->hotelPropertyId,
                    roomNumber: $existing->roomNumber,
                    bedType: $existing->bedType,
                    bathroomType: $existing->bathroomType,
                    stayStart: $stay->stayStart,
                    stayEnd: $stay->stayEnd,
                    stayRating: $existing->stayRating,
                    lastStayPrice: $existing->lastStayPrice,
                    currency: $existing->currency,
                    bookingSource: $existing->bookingSource ?? $stay->bookingSource,
                    confirmationCode: $stay->confirmationCode,
                    wouldReturn: $existing->wouldReturn,
                    notes: $this->mergeImportNotes($existing->notes, $stay->notes),
                    isPrivate: $existing->isPrivate,
                ), $actorUserId);
                return ['stay' => $updated, 'created' => false];
            }
        }

        return ['stay' => $this->create($stay, $actorUserId), 'created' => true];
    }

    /**
     * Cancel a stay found by confirmation code, or by hotel name + dates
     * (Hilton cancellation emails use a different cancellation #).
     *
     * Email-imported stays are deleted; manually entered stays get a
     * [CANCELLED] note prefix so history is preserved.
     */
    public function cancelFromImport(
        int $userId,
        ?string $confirmationCode,
        ?string $propertyName,
        ?string $checkIn,
        ?string $checkOut,
        ?int $actorUserId = null,
    ): ?HotelStay {
        $stay = null;
        if ($confirmationCode !== null && trim($confirmationCode) !== '') {
            $stay = $this->findByConfirmationCode($userId, $confirmationCode);
        }
        if ($stay === null) {
            $stay = $this->findForCancelMatch($userId, $propertyName, $checkIn, $checkOut);
        }
        if ($stay === null) {
            return null;
        }

        if (($stay->bookingSource ?? '') === 'email_import') {
            $this->delete((int) $stay->id, $actorUserId);
            return $stay;
        }

        $notes = $stay->notes ?? '';
        if (!str_starts_with($notes, '[CANCELLED]')) {
            $notes = '[CANCELLED via email] ' . $notes;
        }
        return $this->update(new HotelStay(
            id: $stay->id,
            userId: $stay->userId,
            hotelPropertyId: $stay->hotelPropertyId,
            roomNumber: $stay->roomNumber,
            bedType: $stay->bedType,
            bathroomType: $stay->bathroomType,
            stayStart: $stay->stayStart,
            stayEnd: $stay->stayEnd,
            stayRating: $stay->stayRating,
            lastStayPrice: $stay->lastStayPrice,
            currency: $stay->currency,
            bookingSource: $stay->bookingSource,
            confirmationCode: $stay->confirmationCode,
            wouldReturn: $stay->wouldReturn,
            notes: trim($notes),
            isPrivate: $stay->isPrivate,
        ), $actorUserId);
    }

    private function mergeImportNotes(?string $existing, ?string $incoming): ?string
    {
        if ($incoming === null || trim($incoming) === '') {
            return $existing;
        }
        if ($existing === null || trim($existing) === '') {
            return $incoming;
        }
        if (str_contains($existing, $incoming)) {
            return $existing;
        }
        return $existing;
    }

    public function update(HotelStay $stay, ?int $actorUserId = null): HotelStay
    {
        if ($stay->id === null) {
            throw new \InvalidArgumentException('Cannot update a HotelStay without an id.');
        }
        $this->validate($stay);

        $existing = $this->find($stay->id);
        $data = $stay->toArray();
        $id = $data['id'];
        unset($data['id']);
        $params = $this->coerceForDb($data);
        $assignments = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($params)));
        $params['id'] = $id;

        $this->db->execute(
            "UPDATE hotel_stays SET {$assignments}, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            $params
        );
        $this->db->audit($actorUserId, 'update', 'hotel_stays', $id, [
            'hotel_property_id' => $stay->hotelPropertyId,
        ]);

        $this->properties()->recomputeOverallRating($stay->hotelPropertyId);
        if ($existing !== null && $existing->hotelPropertyId !== $stay->hotelPropertyId) {
            $this->properties()->recomputeOverallRating($existing->hotelPropertyId);
        }

        $updated = $this->find($id);
        if ($updated === null) {
            throw new \RuntimeException('Hotel stay update succeeded but row could not be re-read.');
        }
        return $updated;
    }

    public function delete(int $id, ?int $actorUserId = null): void
    {
        $existing = $this->find($id);
        $this->db->execute('DELETE FROM hotel_stays WHERE id = :id', ['id' => $id]);
        $this->db->audit($actorUserId, 'delete', 'hotel_stays', $id, []);
        $this->logger->info('Hotel stay deleted', ['id' => $id]);
        if ($existing !== null) {
            $this->properties()->recomputeOverallRating($existing->hotelPropertyId);
        }
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

        if ($stay->hotelPropertyId < 1) {
            $errors[] = 'hotel_property_id is required.';
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
        if ($stay->stayRating !== null && ($stay->stayRating < 1 || $stay->stayRating > 5)) {
            $errors[] = 'stay_rating must be between 1 and 5.';
        }
        if ($stay->bedType !== null && !in_array($stay->bedType, HotelPropertyRepository::BED_TYPES, true)) {
            $errors[] = 'bed_type must be king, queen, or dual_queen.';
        }
        if ($stay->bathroomType !== null && !in_array($stay->bathroomType, HotelPropertyRepository::BATHROOM_TYPES, true)) {
            $errors[] = 'bathroom_type must be tub or walk_in_shower.';
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
        $data['is_private'] = !empty($data['is_private']) ? 1 : 0;
        if (array_key_exists('would_return', $data)) {
            $data['would_return'] = $data['would_return'] === null ? null : ($data['would_return'] ? 1 : 0);
        }
        return $data;
    }
}
