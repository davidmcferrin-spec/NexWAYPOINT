<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

final class TripRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function find(int $id): ?Trip
    {
        $row = $this->db->fetchOne('SELECT * FROM trips WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Trip::fromRow($row);
    }

    /**
     * @return Trip[]
     */
    public function findForOwner(int $ownerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM trips WHERE owner_id = :owner_id ORDER BY start_date DESC',
            ['owner_id' => $ownerId]
        );
        return array_map(static fn (array $r) => Trip::fromRow($r), $rows);
    }

    /**
     * Filter the owner's trips for the history UI.
     *
     * @param 'all'|'upcoming'|'past'|'cancelled' $scope
     * @return Trip[]
     */
    public function searchForOwner(int $ownerId, string $scope = 'all', ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('today');
        $today = $asOf->format('Y-m-d');
        $trips = $this->findForOwner($ownerId);

        if ($scope === 'all') {
            return $trips;
        }

        return array_values(array_filter(
            $trips,
            static function (Trip $trip) use ($scope, $today): bool {
                $cancelled = $trip->status === 'cancelled';
                $ended = $trip->endDate < $today || $trip->status === 'completed';
                return match ($scope) {
                    'cancelled' => $cancelled,
                    'past' => !$cancelled && $ended,
                    'upcoming' => !$cancelled && !$ended,
                    default => true,
                };
            }
        ));
    }

    /**
     * Trips that overlap "now" or start within the given number of days --
     * used by TripStatusEngine and the team dashboard.
     *
     * @return Trip[]
     */
    public function findActiveOrUpcoming(int $ownerId, int $daysAhead = 48, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('today');
        $rows = $this->db->fetchAll(
            "SELECT * FROM trips
             WHERE owner_id = :owner_id
               AND status IN ('planned','active')
               AND start_date <= :until
               AND end_date >= :today
             ORDER BY start_date ASC",
            [
                'owner_id' => $ownerId,
                'today' => $asOf->format('Y-m-d'),
                'until' => $asOf->modify("+{$daysAhead} days")->format('Y-m-d'),
            ]
        );
        return array_map(static fn (array $r) => Trip::fromRow($r), $rows);
    }

    public function create(Trip $trip, ?int $actorUserId = null): Trip
    {
        $data = $trip->toArray();
        unset($data['id']);
        $data['is_private'] = $data['is_private'] ? 1 : 0;

        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c) => ":{$c}", $columns);

        $this->db->execute(
            sprintf('INSERT INTO trips (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders)),
            $data
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'trips', $id, ['destination_city' => $trip->destinationCity]);
        $this->logger->info('Trip created', ['id' => $id, 'owner_id' => $trip->ownerId]);

        $created = $this->find($id);
        if ($created === null) {
            throw new \RuntimeException('Trip insert succeeded but row could not be re-read.');
        }
        return $created;
    }

    public function setPrivacy(int $tripId, bool $isPrivate, ?int $actorUserId = null): void
    {
        $this->db->execute(
            'UPDATE trips SET is_private = :p, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['p' => $isPrivate ? 1 : 0, 'id' => $tripId]
        );
        $this->db->audit($actorUserId, 'update_privacy', 'trips', $tripId, ['is_private' => $isPrivate]);
    }

    public function update(Trip $trip, ?int $actorUserId = null): Trip
    {
        if ($trip->id === null) {
            throw new \InvalidArgumentException('Cannot update a Trip without an id.');
        }
        $data = $trip->toArray();
        $id = (int) $data['id'];
        unset($data['id']);
        $data['is_private'] = !empty($data['is_private']) ? 1 : 0;
        $assignments = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($data)));
        $data['id'] = $id;

        $this->db->execute(
            "UPDATE trips SET {$assignments}, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            $data
        );
        $this->db->audit($actorUserId, 'update', 'trips', $id, [
            'destination_city' => $trip->destinationCity,
            'status' => $trip->status,
        ]);

        $updated = $this->find($id);
        if ($updated === null) {
            throw new \RuntimeException('Trip update succeeded but row could not be re-read.');
        }
        return $updated;
    }

    public function addSegment(TripSegment $segment, ?int $actorUserId = null): TripSegment
    {
        $data = $segment->toArray();
        unset($data['id']);

        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c) => ":{$c}", $columns);

        $this->db->execute(
            sprintf('INSERT INTO trip_segments (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders)),
            $data
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'trip_segments', $id, ['segment_type' => $segment->segmentType]);

        $row = $this->db->fetchOne('SELECT * FROM trip_segments WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new \RuntimeException('Trip segment insert succeeded but row could not be re-read.');
        }
        return TripSegment::fromRow($row);
    }

    /**
     * @return TripSegment[]
     */
    public function findSegmentsByConfirmation(int $ownerId, string $confirmationCode): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ts.* FROM trip_segments ts
             INNER JOIN trips t ON t.id = ts.trip_id
             WHERE t.owner_id = :owner_id
               AND UPPER(ts.confirmation_code) = UPPER(:code)
             ORDER BY ts.depart_dt ASC, ts.id ASC',
            ['owner_id' => $ownerId, 'code' => trim($confirmationCode)]
        );
        return array_map(static fn (array $r) => TripSegment::fromRow($r), $rows);
    }

    /**
     * Create or replace flight/train legs for a confirmation/PNR.
     *
     * @param list<array{
     *   segment_type?: string,
     *   carrier_id?: ?int,
     *   carrier?: ?string,
     *   flight_number?: ?string,
     *   origin?: ?string,
     *   destination?: ?string,
     *   depart_dt?: ?string,
     *   arrive_dt?: ?string,
     *   confirmation_code?: ?string
     * }> $legs
     * @return array{trip: Trip, segments: TripSegment[], created: bool}
     */
    public function upsertItineraryByConfirmation(
        int $ownerId,
        string $confirmationCode,
        array $legs,
        ?string $destinationCity = null,
        ?int $actorUserId = null,
        ?int $sourceParseLogId = null,
    ): array {
        $code = strtoupper(trim($confirmationCode));
        if ($code === '' || $legs === []) {
            throw new \InvalidArgumentException('Confirmation code and at least one leg are required.');
        }

        $existing = $this->findSegmentsByConfirmation($ownerId, $code);
        $trip = null;
        $created = false;

        if ($existing !== []) {
            $trip = $this->find($existing[0]->tripId);
            foreach ($existing as $seg) {
                if ($seg->id !== null) {
                    $this->db->execute('DELETE FROM trip_segments WHERE id = :id', ['id' => $seg->id]);
                }
            }
        }

        $dates = $this->dateBoundsFromLegs($legs);
        $dest = $destinationCity
            ?? $this->inferDestinationCity($legs)
            ?? 'Travel';
        $isPast = $this->isPastEndDate($dates['end']);
        if ($isPast) {
            $tripStatus = 'completed';
        } elseif ($trip !== null && $trip->status === 'cancelled') {
            $tripStatus = 'planned';
        } elseif ($trip !== null) {
            $tripStatus = $trip->status;
        } else {
            $tripStatus = 'planned';
        }
        $segmentStatus = $isPast ? 'completed' : 'scheduled';

        if ($trip === null) {
            $trip = $this->create(new Trip(
                id: null,
                ownerId: $ownerId,
                destinationCity: $dest,
                startDate: $dates['start'],
                endDate: $dates['end'],
                status: $tripStatus,
                tripPurpose: null,
                notes: 'Auto-imported from email confirmation ' . $code,
                isPrivate: false,
            ), $actorUserId);
            $created = true;
        } else {
            $trip = $this->update(new Trip(
                id: $trip->id,
                ownerId: $trip->ownerId,
                destinationCity: $dest,
                startDate: $dates['start'],
                endDate: $dates['end'],
                status: $tripStatus,
                tripPurpose: $trip->tripPurpose,
                notes: $trip->notes,
                isPrivate: $trip->isPrivate,
            ), $actorUserId);
        }

        $segments = [];
        foreach ($legs as $leg) {
            $segments[] = $this->addSegment(new TripSegment(
                id: null,
                tripId: (int) $trip->id,
                segmentType: (string) ($leg['segment_type'] ?? 'flight'),
                segmentSubtype: null,
                carrierId: isset($leg['carrier_id']) ? (int) $leg['carrier_id'] : null,
                carrier: $leg['carrier'] ?? null,
                flightNumber: isset($leg['flight_number']) ? (string) $leg['flight_number'] : null,
                confirmationCode: $code,
                origin: $leg['origin'] ?? null,
                destination: $leg['destination'] ?? null,
                departDt: $this->normalizeDateTime($leg['depart_dt'] ?? null),
                arriveDt: $this->normalizeDateTime($leg['arrive_dt'] ?? null),
                hotelStayId: null,
                status: $segmentStatus,
                sourceParseLogId: $sourceParseLogId,
            ), $actorUserId);
        }

        $this->logger->info('Itinerary upserted from email', [
            'trip_id' => $trip->id,
            'confirmation_code' => $code,
            'legs' => count($segments),
            'created' => $created,
        ]);

        return ['trip' => $trip, 'segments' => $segments, 'created' => $created];
    }

    /**
     * Mark all segments for a PNR cancelled; cancel the trip if no active legs remain.
     *
     * @return int Number of segments cancelled
     */
    public function cancelByConfirmation(int $ownerId, string $confirmationCode, ?int $actorUserId = null): int
    {
        $segments = $this->findSegmentsByConfirmation($ownerId, $confirmationCode);
        if ($segments === []) {
            return 0;
        }

        $tripIds = [];
        foreach ($segments as $seg) {
            if ($seg->id === null) {
                continue;
            }
            $this->updateSegmentStatus($seg->id, 'cancelled', $actorUserId);
            $tripIds[$seg->tripId] = true;
        }

        foreach (array_keys($tripIds) as $tripId) {
            $remaining = $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM trip_segments
                 WHERE trip_id = :trip_id AND status NOT IN ('cancelled','completed')",
                ['trip_id' => $tripId]
            );
            if ((int) ($remaining['c'] ?? 0) === 0) {
                $trip = $this->find($tripId);
                if ($trip !== null) {
                    $this->update(new Trip(
                        id: $trip->id,
                        ownerId: $trip->ownerId,
                        destinationCity: $trip->destinationCity,
                        startDate: $trip->startDate,
                        endDate: $trip->endDate,
                        status: 'cancelled',
                        tripPurpose: $trip->tripPurpose,
                        notes: $trip->notes,
                        isPrivate: $trip->isPrivate,
                    ), $actorUserId);
                }
            }
        }

        return count($segments);
    }

    private function isPastEndDate(string $endDate, ?\DateTimeImmutable $asOf = null): bool
    {
        $today = ($asOf ?? new \DateTimeImmutable('today'))->format('Y-m-d');
        return substr($endDate, 0, 10) < $today;
    }

    /**
     * @param list<array<string, mixed>> $legs
     * @return array{start: string, end: string}
     */
    private function dateBoundsFromLegs(array $legs): array
    {
        $dates = [];
        foreach ($legs as $leg) {
            foreach (['depart_dt', 'arrive_dt'] as $key) {
                $raw = $leg[$key] ?? null;
                if (!is_string($raw) || trim($raw) === '') {
                    continue;
                }
                $dt = $this->normalizeDateTime($raw);
                if ($dt !== null) {
                    $dates[] = substr($dt, 0, 10);
                }
            }
        }
        if ($dates === []) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            return ['start' => $today, 'end' => $today];
        }
        sort($dates);
        return ['start' => $dates[0], 'end' => $dates[count($dates) - 1]];
    }

    /**
     * @param list<array<string, mixed>> $legs
     */
    private function inferDestinationCity(array $legs): ?string
    {
        $last = $legs[count($legs) - 1];
        $dest = $last['destination'] ?? null;
        return is_string($dest) && trim($dest) !== '' ? trim($dest) : null;
    }

    private function normalizeDateTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return $value . ' 00:00:00';
            }
            $dt = new \DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return TripSegment[]
     */
    public function segmentsForTrip(int $tripId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM trip_segments WHERE trip_id = :trip_id ORDER BY depart_dt ASC',
            ['trip_id' => $tripId]
        );
        return array_map(static fn (array $r) => TripSegment::fromRow($r), $rows);
    }

    /**
     * All segments (any trip/owner) with a depart_dt in the given window and
     * not yet in a terminal state -- used by the FlightAware enrichment sweep.
     *
     * @return TripSegment[]
     */
    public function findSegmentsNeedingEnrichment(int $hoursAhead = 48): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM trip_segments
             WHERE segment_type = 'flight'
               AND status NOT IN ('landed','cancelled','completed')
               AND depart_dt <= :until",
            ['until' => (new \DateTimeImmutable('now'))->modify("+{$hoursAhead} hours")->format('Y-m-d H:i:s')]
        );
        return array_map(static fn (array $r) => TripSegment::fromRow($r), $rows);
    }

    public function updateSegmentStatus(int $segmentId, string $status, ?int $actorUserId = null): void
    {
        $this->db->execute(
            'UPDATE trip_segments SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => $status, 'id' => $segmentId]
        );
        $this->db->audit($actorUserId, 'update_status', 'trip_segments', $segmentId, ['status' => $status]);
    }

    public function setLatestUserStatus(
        int $userId,
        string $status,
        ?string $note,
        ?int $actorUserId = null,
        ?string $locationCity = null,
        ?string $locationState = null,
    ): void {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->setStatusOverride(
            $userId,
            $status,
            $note,
            $today,
            $actorUserId,
            null,
            $locationCity,
            $locationState,
        );
    }

    /**
     * Set a manual work-status override from today through $expiresOn (inclusive).
     * Travel segments still take precedence over this in TripStatusEngine.
     * Remote requires location_city (and preferably location_state).
     */
    public function setStatusOverride(
        int $userId,
        string $status,
        ?string $note,
        string $expiresOn,
        ?int $actorUserId = null,
        ?string $effectiveDate = null,
        ?string $locationCity = null,
        ?string $locationState = null,
    ): void {
        $allowed = ['home', 'office', 'remote', 'unavailable'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Status must be home, office, remote, or unavailable.');
        }

        $effectiveDate = $effectiveDate ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
        $expiresOn = trim($expiresOn);
        if ($expiresOn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn)) {
            throw new \InvalidArgumentException('Override expiry date is required (YYYY-MM-DD).');
        }
        if ($expiresOn < $effectiveDate) {
            throw new \InvalidArgumentException('Expiry date must be on or after the start date.');
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        $locationCity = $locationCity !== null ? trim($locationCity) : null;
        $locationState = $locationState !== null ? trim($locationState) : null;
        if ($locationCity === '') {
            $locationCity = null;
        }
        if ($locationState === '') {
            $locationState = null;
        }

        if ($status === 'remote') {
            if ($locationCity === null) {
                throw new \InvalidArgumentException('City is required when status is Working remote.');
            }
        } else {
            $locationCity = null;
            $locationState = null;
        }

        $hasExpires = $this->db->columnExists('user_status_overrides', 'expires_on');
        $hasLocation = $this->db->columnExists('user_status_overrides', 'location_city');

        $cols = ['user_id', 'status', 'note', 'effective_date'];
        $params = [
            'user_id' => $userId,
            'status' => $status,
            'note' => $note,
            'date' => $effectiveDate,
        ];
        if ($hasExpires) {
            $cols[] = 'expires_on';
            $params['expires'] = $expiresOn;
        }
        if ($hasLocation) {
            $cols[] = 'location_city';
            $cols[] = 'location_state';
            $params['loc_city'] = $locationCity;
            $params['loc_state'] = $locationState;
        }

        $placeholders = [];
        foreach ($cols as $col) {
            $placeholders[] = match ($col) {
                'effective_date' => ':date',
                'expires_on' => ':expires',
                'location_city' => ':loc_city',
                'location_state' => ':loc_state',
                default => ':' . $col,
            };
        }

        $updateParts = ['status = excluded.status', 'note = excluded.note'];
        $mysqlUpdate = ['status = VALUES(status)', 'note = VALUES(note)'];
        if ($hasExpires) {
            $updateParts[] = 'expires_on = excluded.expires_on';
            $mysqlUpdate[] = 'expires_on = VALUES(expires_on)';
        }
        if ($hasLocation) {
            $updateParts[] = 'location_city = excluded.location_city';
            $updateParts[] = 'location_state = excluded.location_state';
            $mysqlUpdate[] = 'location_city = VALUES(location_city)';
            $mysqlUpdate[] = 'location_state = VALUES(location_state)';
        }

        $colList = implode(', ', $cols);
        $valList = implode(', ', $placeholders);

        if ($this->db->driver() === 'sqlite') {
            $this->db->execute(
                "INSERT INTO user_status_overrides ({$colList})
                 VALUES ({$valList})
                 ON CONFLICT(user_id, effective_date) DO UPDATE SET " . implode(', ', $updateParts),
                $params
            );
        } else {
            $this->db->execute(
                "INSERT INTO user_status_overrides ({$colList})
                 VALUES ({$valList})
                 ON DUPLICATE KEY UPDATE " . implode(', ', $mysqlUpdate),
                $params
            );
        }

        $this->db->audit($actorUserId, 'set_status_override', 'user_status_overrides', null, [
            'user_id' => $userId,
            'status' => $status,
            'expires_on' => $expiresOn,
            'location_city' => $locationCity,
            'location_state' => $locationState,
        ]);
    }

    /**
     * End any overrides covering $asOf (default today) so status falls back to travel/Home.
     */
    public function clearStatusOverride(int $userId, ?int $actorUserId = null, ?\DateTimeImmutable $asOf = null): int
    {
        $asOf = $asOf ?? new \DateTimeImmutable('today');
        $today = $asOf->format('Y-m-d');
        $yesterday = $asOf->modify('-1 day')->format('Y-m-d');
        $hasExpires = $this->db->columnExists('user_status_overrides', 'expires_on');

        if ($hasExpires) {
            $rows = $this->db->fetchAll(
                'SELECT id FROM user_status_overrides
                 WHERE user_id = :user_id
                   AND effective_date <= :today
                   AND COALESCE(expires_on, effective_date) >= :today2',
                ['user_id' => $userId, 'today' => $today, 'today2' => $today]
            );
            foreach ($rows as $row) {
                $this->db->execute(
                    'UPDATE user_status_overrides SET expires_on = :exp WHERE id = :id',
                    ['exp' => $yesterday, 'id' => (int) $row['id']]
                );
            }
            $count = count($rows);
        } else {
            $this->db->execute(
                'DELETE FROM user_status_overrides WHERE user_id = :user_id AND effective_date = :today',
                ['user_id' => $userId, 'today' => $today]
            );
            $count = 1; // best-effort; delete doesn't return rowCount via our wrapper consistently
        }

        $this->db->audit($actorUserId, 'clear_status_override', 'user_status_overrides', null, [
            'user_id' => $userId,
            'as_of' => $today,
        ]);
        return $count;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeStatusOverride(int $userId, ?\DateTimeImmutable $asOf = null): ?array
    {
        $asOf = $asOf ?? new \DateTimeImmutable('today');
        $today = $asOf->format('Y-m-d');
        $hasExpires = $this->db->columnExists('user_status_overrides', 'expires_on');

        if ($hasExpires) {
            return $this->db->fetchOne(
                'SELECT * FROM user_status_overrides
                 WHERE user_id = :user_id
                   AND effective_date <= :today
                   AND COALESCE(expires_on, effective_date) >= :today2
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1',
                ['user_id' => $userId, 'today' => $today, 'today2' => $today]
            );
        }

        return $this->db->fetchOne(
            'SELECT * FROM user_status_overrides
             WHERE user_id = :user_id AND effective_date = :today
             ORDER BY created_at DESC, id DESC
             LIMIT 1',
            ['user_id' => $userId, 'today' => $today]
        );
    }

    /**
     * @return array<string, mixed>|null
     * @deprecated Use activeStatusOverride()
     */
    public function latestUserStatusOverride(int $userId): ?array
    {
        return $this->activeStatusOverride($userId);
    }
}
