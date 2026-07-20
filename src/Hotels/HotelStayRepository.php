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
     * If no confirmation match, soft-match same user + property + check-in
     * (and check-out when provided) so a manual stay absorbs the email import
     * instead of creating a duplicate.
     *
     * @return array{stay: HotelStay, created: bool}
     */
    public function upsertFromImport(HotelStay $stay, ?int $actorUserId = null): array
    {
        if ($stay->confirmationCode !== null && trim($stay->confirmationCode) !== '') {
            $existing = $this->findByConfirmationCode($stay->userId, $stay->confirmationCode);
            if ($existing !== null) {
                return [
                    'stay' => $this->applyImportOntoExisting($existing, $stay, $actorUserId),
                    'created' => false,
                ];
            }
        }

        $soft = $this->findForImportDateMatch(
            $stay->userId,
            $stay->hotelPropertyId,
            $stay->stayStart,
            $stay->stayEnd,
        );
        if ($soft !== null) {
            return [
                'stay' => $this->applyImportOntoExisting($soft, $stay, $actorUserId),
                'created' => false,
            ];
        }

        return ['stay' => $this->create($stay, $actorUserId), 'created' => true];
    }

    /**
     * Same property + check-in date for this user (optional exact check-out).
     * Used when email import has no confirmation match against a manual stay.
     */
    public function findForImportDateMatch(
        int $userId,
        int $hotelPropertyId,
        string $checkIn,
        ?string $checkOut = null,
    ): ?HotelStay {
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_stays
             WHERE user_id = :user_id
               AND hotel_property_id = :pid
               AND stay_start = :start
             ORDER BY id DESC LIMIT 10',
            [
                'user_id' => $userId,
                'pid' => $hotelPropertyId,
                'start' => $checkIn,
            ]
        );
        if ($rows === []) {
            return null;
        }
        if ($checkOut !== null && $checkOut !== '') {
            foreach ($rows as $row) {
                if (($row['stay_end'] ?? null) === $checkOut) {
                    return HotelStay::fromRow($row);
                }
            }
        }
        return HotelStay::fromRow($rows[0]);
    }

    /**
     * Merge $absorbId into $keepId (same owner). Keeps user-entered fields on
     * the survivor when set; fills gaps from the absorbed stay (confirmation,
     * price, dates from import, etc.). Re-points trip segments, photos, and
     * visibility blocks, then deletes the absorbed stay.
     */
    public function mergeStays(int $keepId, int $absorbId, ?int $actorUserId = null): HotelStay
    {
        if ($keepId === $absorbId) {
            throw new \InvalidArgumentException('Cannot merge a stay into itself.');
        }

        $keep = $this->find($keepId);
        $absorb = $this->find($absorbId);
        if ($keep === null || $absorb === null) {
            throw new \InvalidArgumentException('One or both stays were not found.');
        }
        if ($keep->userId !== $absorb->userId) {
            throw new \InvalidArgumentException('Stays must belong to the same user.');
        }

        $prefer = static function (mixed $primary, mixed $fallback): mixed {
            if ($primary === null) {
                return $fallback;
            }
            if (is_string($primary) && trim($primary) === '') {
                return $fallback;
            }
            return $primary;
        };

        // Email confirmation usually has the authoritative booking window.
        $absorbHasConf = $absorb->confirmationCode !== null && trim($absorb->confirmationCode) !== '';
        $stayStart = $absorbHasConf ? $absorb->stayStart : $keep->stayStart;
        $stayEnd = $absorbHasConf ? $absorb->stayEnd : $keep->stayEnd;

        $merged = $this->update(new HotelStay(
            id: $keep->id,
            userId: $keep->userId,
            hotelPropertyId: $keep->hotelPropertyId > 0
                ? $keep->hotelPropertyId
                : $absorb->hotelPropertyId,
            roomNumber: $prefer($keep->roomNumber, $absorb->roomNumber),
            bedType: $prefer($keep->bedType, $absorb->bedType),
            bathroomType: $prefer($keep->bathroomType, $absorb->bathroomType),
            stayStart: $stayStart,
            stayEnd: $stayEnd,
            stayRating: $prefer($keep->stayRating, $absorb->stayRating),
            lastStayPrice: $prefer($keep->lastStayPrice, $absorb->lastStayPrice),
            currency: $keep->currency !== '' ? $keep->currency : $absorb->currency,
            bookingSource: $prefer($keep->bookingSource, $absorb->bookingSource),
            confirmationCode: $prefer($keep->confirmationCode, $absorb->confirmationCode),
            wouldReturn: $prefer($keep->wouldReturn, $absorb->wouldReturn),
            notes: $this->mergeImportNotes($keep->notes, $absorb->notes),
            isPrivate: $keep->isPrivate || $absorb->isPrivate,
        ), $actorUserId);

        $this->db->execute(
            'UPDATE trip_segments SET hotel_stay_id = :keep WHERE hotel_stay_id = :absorb',
            ['keep' => $keepId, 'absorb' => $absorbId]
        );
        $this->db->execute(
            'UPDATE hotel_photos SET hotel_stay_id = :keep WHERE hotel_stay_id = :absorb',
            ['keep' => $keepId, 'absorb' => $absorbId]
        );

        // Move visibility blocks that aren't already on the survivor.
        $existingBlocks = $this->db->fetchAll(
            "SELECT blocked_user_id FROM visibility_blocks
             WHERE resource_type = 'hotel_stay' AND resource_id = :keep",
            ['keep' => $keepId]
        );
        $blocked = [];
        foreach ($existingBlocks as $row) {
            $blocked[(int) $row['blocked_user_id']] = true;
        }
        $absorbBlocks = $this->db->fetchAll(
            "SELECT * FROM visibility_blocks
             WHERE resource_type = 'hotel_stay' AND resource_id = :absorb",
            ['absorb' => $absorbId]
        );
        foreach ($absorbBlocks as $row) {
            $uid = (int) $row['blocked_user_id'];
            if (!isset($blocked[$uid])) {
                $this->db->execute(
                    "UPDATE visibility_blocks SET resource_id = :keep WHERE id = :id",
                    ['keep' => $keepId, 'id' => (int) $row['id']]
                );
            }
        }
        $this->db->execute(
            "DELETE FROM visibility_blocks WHERE resource_type = 'hotel_stay' AND resource_id = :absorb",
            ['absorb' => $absorbId]
        );

        $this->delete($absorbId, $actorUserId);
        $this->db->audit($actorUserId, 'merge', 'hotel_stays', $keepId, [
            'absorbed_id' => $absorbId,
        ]);
        $this->logger->info('Hotel stays merged', [
            'keep_id' => $keepId,
            'absorb_id' => $absorbId,
        ]);

        $final = $this->find($keepId);
        if ($final === null) {
            throw new \RuntimeException('Merge succeeded but survivor stay could not be re-read.');
        }
        return $final;
    }

    private function applyImportOntoExisting(
        HotelStay $existing,
        HotelStay $incoming,
        ?int $actorUserId,
    ): HotelStay {
        return $this->update(new HotelStay(
            id: $existing->id,
            userId: $existing->userId,
            hotelPropertyId: $incoming->hotelPropertyId > 0
                ? $incoming->hotelPropertyId
                : $existing->hotelPropertyId,
            roomNumber: $existing->roomNumber,
            bedType: $existing->bedType,
            bathroomType: $existing->bathroomType,
            stayStart: $incoming->stayStart,
            stayEnd: $incoming->stayEnd,
            stayRating: $existing->stayRating,
            lastStayPrice: $existing->lastStayPrice ?? $incoming->lastStayPrice,
            currency: $existing->currency !== '' ? $existing->currency : $incoming->currency,
            bookingSource: $existing->bookingSource ?? $incoming->bookingSource,
            confirmationCode: ($existing->confirmationCode !== null && trim($existing->confirmationCode) !== '')
                ? $existing->confirmationCode
                : $incoming->confirmationCode,
            wouldReturn: $existing->wouldReturn,
            notes: $this->mergeImportNotes($existing->notes, $incoming->notes),
            isPrivate: $existing->isPrivate,
        ), $actorUserId);
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
        return trim($existing) . "\n" . trim($incoming);
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
        if ($stay->stayRating !== null && ($stay->stayRating < 0 || $stay->stayRating > 5)) {
            $errors[] = 'stay_rating must be between 0 and 5.';
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
