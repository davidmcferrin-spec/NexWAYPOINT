<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Database;

/**
 * Upsert access to flight_status (one row per segment) and aeroapi_usage_log.
 */
final class FlightStatusRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySegment(int $segmentId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM flight_status WHERE segment_id = :id', ['id' => $segmentId]);
    }

    /**
     * @param array<string, mixed> $fields Any subset of flight_status columns except id/segment_id.
     */
    public function upsert(int $segmentId, array $fields): void
    {
        $existing = $this->findBySegment($segmentId);
        $fields['last_checked_at'] = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        if ($existing === null) {
            $fields['segment_id'] = $segmentId;
            $columns = array_keys($fields);
            $placeholders = array_map(static fn (string $c) => ":{$c}", $columns);
            $this->db->execute(
                sprintf('INSERT INTO flight_status (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders)),
                $fields
            );
            return;
        }

        $assignments = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($fields)));
        $fields['segment_id'] = $segmentId;
        $this->db->execute("UPDATE flight_status SET {$assignments} WHERE segment_id = :segment_id", $fields);
    }

    public function needsRefresh(int $segmentId, int $cacheMinutes): bool
    {
        $existing = $this->findBySegment($segmentId);
        if ($existing === null || $existing['last_checked_at'] === null) {
            return true;
        }
        $lastChecked = new \DateTimeImmutable((string) $existing['last_checked_at']);
        return $lastChecked < (new \DateTimeImmutable('now'))->modify("-{$cacheMinutes} minutes");
    }

    public function recordUsage(string $endpoint, float $estimatedCost = 0.0): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($this->db->driver() === 'sqlite') {
            $this->db->execute(
                'INSERT INTO aeroapi_usage_log (usage_date, endpoint, calls, estimated_cost_usd)
                 VALUES (:date, :endpoint, 1, :cost)
                 ON CONFLICT(usage_date, endpoint) DO UPDATE SET
                    calls = calls + 1,
                    estimated_cost_usd = estimated_cost_usd + :cost2',
                ['date' => $today, 'endpoint' => $endpoint, 'cost' => $estimatedCost, 'cost2' => $estimatedCost]
            );
        } else {
            $this->db->execute(
                'INSERT INTO aeroapi_usage_log (usage_date, endpoint, calls, estimated_cost_usd)
                 VALUES (:date, :endpoint, 1, :cost)
                 ON DUPLICATE KEY UPDATE calls = calls + 1, estimated_cost_usd = estimated_cost_usd + VALUES(estimated_cost_usd)',
                ['date' => $today, 'endpoint' => $endpoint, 'cost' => $estimatedCost]
            );
        }
    }

    public function monthToDateCost(): float
    {
        $monthStart = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $row = $this->db->fetchOne(
            'SELECT SUM(estimated_cost_usd) AS total FROM aeroapi_usage_log WHERE usage_date >= :start',
            ['start' => $monthStart]
        );
        return (float) ($row['total'] ?? 0.0);
    }
}
