<?php

declare(strict_types=1);

namespace NexWaypont\Mail;

use NexWaypont\Core\Database;

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
        $row = $this->db->fetchOne(
            'SELECT id FROM parse_log WHERE mail_uid = :uid AND source = :source',
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
        $this->db->execute(
            'INSERT INTO parse_log (
                received_at, from_address, subject, mail_uid, source, detected_type,
                parse_status, failure_reason, confidence_score, matched_user_id, trip_segment_id
            ) VALUES (
                :received_at, :from_address, :subject, :mail_uid, :source, :detected_type,
                :parse_status, :failure_reason, :confidence_score, :matched_user_id, :trip_segment_id
            )',
            [
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
            ]
        );
        return $this->db->lastInsertId();
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
