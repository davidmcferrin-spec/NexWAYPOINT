<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Database;

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
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM notifications WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = 'SELECT n.*, ts.trip_id AS trip_id
                FROM notifications n
                LEFT JOIN trip_segments ts ON ts.id = n.segment_id
                WHERE n.user_id = :user_id';
        if ($unreadOnly) {
            $sql .= ' AND n.is_read = 0';
        }
        $sql .= ' ORDER BY n.is_read ASC, n.created_at DESC LIMIT ' . max(1, $limit);
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

    /**
     * Mark one notification read only if it belongs to the user.
     */
    public function markReadForUser(int $userId, int $notificationId): bool
    {
        $this->db->execute(
            'UPDATE notifications SET is_read = 1
             WHERE id = :id AND user_id = :user_id AND is_read = 0',
            ['id' => $notificationId, 'user_id' => $userId]
        );
        $row = $this->find($notificationId);
        return $row !== null && (int) $row['user_id'] === $userId && !empty($row['is_read']);
    }

    /**
     * @return int Number of notifications marked read
     */
    public function markAllReadForUser(int $userId): int
    {
        $before = $this->unreadCount($userId);
        if ($before === 0) {
            return 0;
        }
        $this->db->execute(
            'UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0',
            ['user_id' => $userId]
        );
        return $before;
    }
}
