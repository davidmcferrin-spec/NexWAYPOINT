<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\Database;

/**
 * Audit trail for inbound mail (parse_log table). Deliberately never
 * accepts or stores raw email body content -- only metadata and the
 * outcome of parsing.
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
        ];

        // Same IMAP UID can be retried after a failed parse; update the row.
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
            $row = $this->db->fetchOne(
                'SELECT id FROM parse_log WHERE mail_uid = :uid AND source = :source',
                ['uid' => $mailUid, 'source' => $source]
            );
            return (int) ($row['id'] ?? $this->db->lastInsertId());
        }

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

        $row = $this->db->fetchOne(
            'SELECT id FROM parse_log WHERE mail_uid = :uid AND source = :source',
            ['uid' => $mailUid, 'source' => $source]
        );
        return (int) ($row['id'] ?? $this->db->lastInsertId());
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
}
