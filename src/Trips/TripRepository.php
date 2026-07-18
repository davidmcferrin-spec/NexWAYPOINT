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

    public function setLatestUserStatus(int $userId, string $status, ?string $note, ?int $actorUserId = null): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($this->db->driver() === 'sqlite') {
            $this->db->execute(
                'INSERT INTO user_status_overrides (user_id, status, note, effective_date)
                 VALUES (:user_id, :status, :note, :date)
                 ON CONFLICT(user_id, effective_date) DO UPDATE SET status = excluded.status, note = excluded.note',
                ['user_id' => $userId, 'status' => $status, 'note' => $note, 'date' => $today]
            );
        } else {
            $this->db->execute(
                'INSERT INTO user_status_overrides (user_id, status, note, effective_date)
                 VALUES (:user_id, :status, :note, :date)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)',
                ['user_id' => $userId, 'status' => $status, 'note' => $note, 'date' => $today]
            );
        }
        $this->db->audit($actorUserId, 'set_status_override', 'user_status_overrides', null, ['user_id' => $userId, 'status' => $status]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestUserStatusOverride(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_status_overrides WHERE user_id = :user_id ORDER BY effective_date DESC LIMIT 1',
            ['user_id' => $userId]
        );
    }
}
