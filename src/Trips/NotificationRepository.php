<?php

declare(strict_types=1);

namespace NexWaypont\Trips;

use NexWaypont\Core\Database;

final class NotificationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, ?int $segmentId, string $alertType, string $message): int
    {
        $this->db->execute(
            'INSERT INTO notifications (user_id, segment_id, alert_type, message) VALUES (:user_id, :segment_id, :alert_type, :message)',
            ['user_id' => $userId, 'segment_id' => $segmentId, 'alert_type' => $alertType, 'message' => $message]
        );
        return $this->db->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = :user_id';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, $limit);
        return $this->db->fetchAll($sql, ['user_id' => $userId]);
    }

    public function unreadCount(int $userId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_id = :user_id AND is_read = 0',
            ['user_id' => $userId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public function markRead(int $notificationId): void
    {
        $this->db->execute('UPDATE notifications SET is_read = 1 WHERE id = :id', ['id' => $notificationId]);
    }
}
