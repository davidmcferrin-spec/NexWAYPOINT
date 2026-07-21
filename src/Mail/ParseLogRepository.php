<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\Database;

/**
 * Audit trail for inbound mail (parse_log table). Raw bodies are not stored
 * in the DB; optional short-lived files live under storage/mail_raw/.
 */
final class ParseLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function alreadyProcessed(string $mailUid, string $source): bool
    {
        // Failed rows may be retried after moving mail back to INBOX (same UID).
        $row = $this->db->fetchOne(
            "SELECT id FROM parse_log
             WHERE mail_uid = :uid AND source = :source
               AND parse_status IN ('success', 'ignored')",
            ['uid' => $mailUid, 'source' => $source]
        );
        return $row !== null;
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM parse_log WHERE id = :id', ['id' => $id]);
        return $row !== null ? $row : null;
    }

    /**
     * @param array{
     *   trip_id?: ?int,
     *   hotel_stay_id?: ?int,
     *   raw_path?: ?string,
     *   raw_expires_at?: ?string
     * } $extra
     */
    public function record(
        \DateTimeImmutable $receivedAt,
        string $fromAddress,
        string $subject,
        string $mailUid,
        string $source,
        ?string $detectedType,
        string $parseStatus,
        ?string $failureReason,
        ?float $confidenceScore,
        ?int $matchedUserId,
        ?int $tripSegmentId,
        array $extra = [],
    ): int {
        $params = [
            'received_at' => $receivedAt->format('Y-m-d H:i:s'),
            'from_address' => $fromAddress,
            'subject' => mb_substr($subject, 0, 500),
            'mail_uid' => $mailUid,
            'source' => $source,
            'detected_type' => $detectedType,
            'parse_status' => $parseStatus,
            'failure_reason' => $failureReason,
            'confidence_score' => $confidenceScore,
            'matched_user_id' => $matchedUserId,
            'trip_segment_id' => $tripSegmentId,
            'trip_id' => $extra['trip_id'] ?? null,
            'hotel_stay_id' => $extra['hotel_stay_id'] ?? null,
            'raw_path' => $extra['raw_path'] ?? null,
            'raw_expires_at' => $extra['raw_expires_at'] ?? null,
        ];

        $hasExtended = $this->db->columnExists('parse_log', 'trip_id');

        if (!$hasExtended) {
            unset($params['trip_id'], $params['hotel_stay_id'], $params['raw_path'], $params['raw_expires_at']);
            return $this->recordLegacy($params);
        }

        if ($this->db->driver() === 'sqlite') {
            $this->db->execute(
                'INSERT INTO parse_log (
                    received_at, from_address, subject, mail_uid, source, detected_type,
                    parse_status, failure_reason, confidence_score, matched_user_id,
                    trip_segment_id, trip_id, hotel_stay_id, raw_path, raw_expires_at
                ) VALUES (
                    :received_at, :from_address, :subject, :mail_uid, :source, :detected_type,
                    :parse_status, :failure_reason, :confidence_score, :matched_user_id,
                    :trip_segment_id, :trip_id, :hotel_stay_id, :raw_path, :raw_expires_at
                )
                ON CONFLICT(mail_uid, source) DO UPDATE SET
                    received_at = excluded.received_at,
                    from_address = excluded.from_address,
                    subject = excluded.subject,
                    detected_type = excluded.detected_type,
                    parse_status = excluded.parse_status,
                    failure_reason = excluded.failure_reason,
                    confidence_score = excluded.confidence_score,
                    matched_user_id = excluded.matched_user_id,
                    trip_segment_id = excluded.trip_segment_id,
                    trip_id = excluded.trip_id,
                    hotel_stay_id = excluded.hotel_stay_id,
                    raw_path = COALESCE(excluded.raw_path, parse_log.raw_path),
                    raw_expires_at = COALESCE(excluded.raw_expires_at, parse_log.raw_expires_at)',
                $params
            );
        } else {
            $this->db->execute(
                'INSERT INTO parse_log (
                    received_at, from_address, subject, mail_uid, source, detected_type,
                    parse_status, failure_reason, confidence_score, matched_user_id,
                    trip_segment_id, trip_id, hotel_stay_id, raw_path, raw_expires_at
                ) VALUES (
                    :received_at, :from_address, :subject, :mail_uid, :source, :detected_type,
                    :parse_status, :failure_reason, :confidence_score, :matched_user_id,
                    :trip_segment_id, :trip_id, :hotel_stay_id, :raw_path, :raw_expires_at
                )
                ON DUPLICATE KEY UPDATE
                    received_at = VALUES(received_at),
                    from_address = VALUES(from_address),
                    subject = VALUES(subject),
                    detected_type = VALUES(detected_type),
                    parse_status = VALUES(parse_status),
                    failure_reason = VALUES(failure_reason),
                    confidence_score = VALUES(confidence_score),
                    matched_user_id = VALUES(matched_user_id),
                    trip_segment_id = VALUES(trip_segment_id),
                    trip_id = VALUES(trip_id),
                    hotel_stay_id = VALUES(hotel_stay_id),
                    raw_path = COALESCE(VALUES(raw_path), raw_path),
                    raw_expires_at = COALESCE(VALUES(raw_expires_at), raw_expires_at)',
                $params
            );
        }

        $row = $this->db->fetchOne(
            'SELECT id FROM parse_log WHERE mail_uid = :uid AND source = :source',
            ['uid' => $mailUid, 'source' => $source]
        );
        return (int) ($row['id'] ?? $this->db->lastInsertId());
    }

    public function updateRawMeta(int $id, string $rawPath, string $rawExpiresAt): void
    {
        if (!$this->db->columnExists('parse_log', 'raw_path')) {
            return;
        }
        $this->db->execute(
            'UPDATE parse_log SET raw_path = :path, raw_expires_at = :expires WHERE id = :id',
            ['path' => $rawPath, 'expires' => $rawExpiresAt, 'id' => $id]
        );
    }

    public function clearRawPaths(array $ids): void
    {
        if ($ids === [] || !$this->db->columnExists('parse_log', 'raw_path')) {
            return;
        }
        foreach ($ids as $id) {
            $this->db->execute(
                'UPDATE parse_log SET raw_path = NULL, raw_expires_at = NULL WHERE id = :id',
                ['id' => (int) $id]
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findWithRawPaths(): array
    {
        if (!$this->db->columnExists('parse_log', 'raw_path')) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT id, raw_path, raw_expires_at FROM parse_log WHERE raw_path IS NOT NULL'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $days, int $limit = 200): array
    {
        $days = max(1, $days);
        $limit = max(1, min(500, $limit));
        $since = (new \DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d H:i:s');
        return $this->db->fetchAll(
            "SELECT * FROM parse_log
             WHERE received_at >= :since
             ORDER BY received_at DESC
             LIMIT {$limit}",
            ['since' => $since]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findFailedQueue(int $limit = 100): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM parse_log WHERE parse_status = 'failed' ORDER BY received_at DESC LIMIT " . max(1, $limit)
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function recordLegacy(array $params): int
    {
        if ($this->db->driver() === 'sqlite') {
            $this->db->execute(
                'INSERT INTO parse_log (
                    received_at, from_address, subject, mail_uid, source, detected_type,
                    parse_status, failure_reason, confidence_score, matched_user_id, trip_segment_id
                ) VALUES (
                    :received_at, :from_address, :subject, :mail_uid, :source, :detected_type,
                    :parse_status, :failure_reason, :confidence_score, :matched_user_id, :trip_segment_id
                )
                ON CONFLICT(mail_uid, source) DO UPDATE SET
                    received_at = excluded.received_at,
                    from_address = excluded.from_address,
                    subject = excluded.subject,
                    detected_type = excluded.detected_type,
                    parse_status = excluded.parse_status,
                    failure_reason = excluded.failure_reason,
                    confidence_score = excluded.confidence_score,
                    matched_user_id = excluded.matched_user_id,
                    trip_segment_id = excluded.trip_segment_id',
                $params
            );
        } else {
            $this->db->execute(
                'INSERT INTO parse_log (
                    received_at, from_address, subject, mail_uid, source, detected_type,
                    parse_status, failure_reason, confidence_score, matched_user_id, trip_segment_id
                ) VALUES (
                    :received_at, :from_address, :subject, :mail_uid, :source, :detected_type,
                    :parse_status, :failure_reason, :confidence_score, :matched_user_id, :trip_segment_id
                )
                ON DUPLICATE KEY UPDATE
                    received_at = VALUES(received_at),
                    from_address = VALUES(from_address),
                    subject = VALUES(subject),
                    detected_type = VALUES(detected_type),
                    parse_status = VALUES(parse_status),
                    failure_reason = VALUES(failure_reason),
                    confidence_score = VALUES(confidence_score),
                    matched_user_id = VALUES(matched_user_id),
                    trip_segment_id = VALUES(trip_segment_id)',
                $params
            );
        }

        $row = $this->db->fetchOne(
            'SELECT id FROM parse_log WHERE mail_uid = :uid AND source = :source',
            ['uid' => $params['mail_uid'], 'source' => $params['source']]
        );
        return (int) ($row['id'] ?? $this->db->lastInsertId());
    }
}
