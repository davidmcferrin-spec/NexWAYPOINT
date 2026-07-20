<?php

declare(strict_types=1);

namespace NexWaypoint\Visibility;

use NexWaypoint\Core\Database;

/**
 * Per-resource hide lists for hotels and trips. Complements field-level
 * VisibilityEngine rules: a blocked viewer sees nothing for that item,
 * and is_private on the resource itself hides it from everyone.
 */
final class VisibilityBlockRepository
{
    public const TYPE_HOTEL_STAY = 'hotel_stay';
    public const TYPE_TRIP = 'trip';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return int[]
     */
    public function blockedUserIds(string $resourceType, int $resourceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT blocked_user_id FROM visibility_blocks
             WHERE resource_type = :type AND resource_id = :id
             ORDER BY blocked_user_id',
            ['type' => $resourceType, 'id' => $resourceId]
        );
        return array_map(static fn (array $r) => (int) $r['blocked_user_id'], $rows);
    }

    public function isBlocked(string $resourceType, int $resourceId, int $viewerUserId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS hit FROM visibility_blocks
             WHERE resource_type = :type AND resource_id = :id AND blocked_user_id = :viewer',
            ['type' => $resourceType, 'id' => $resourceId, 'viewer' => $viewerUserId]
        );
        return $row !== null;
    }

    /**
     * Replace the hide-from list for a resource. Empty array clears all blocks.
     *
     * @param int[] $blockedUserIds
     */
    public function replaceBlocks(
        int $ownerUserId,
        string $resourceType,
        int $resourceId,
        array $blockedUserIds,
        ?int $actorUserId = null
    ): void {
        if (!in_array($resourceType, [self::TYPE_HOTEL_STAY, self::TYPE_TRIP], true)) {
            throw new \InvalidArgumentException("Invalid resource_type '{$resourceType}'.");
        }

        $unique = [];
        foreach ($blockedUserIds as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $ownerUserId) {
                $unique[$id] = $id;
            }
        }

        $this->db->execute(
            'DELETE FROM visibility_blocks WHERE resource_type = :type AND resource_id = :id',
            ['type' => $resourceType, 'id' => $resourceId]
        );

        foreach ($unique as $blockedUserId) {
            $this->db->execute(
                'INSERT INTO visibility_blocks (owner_user_id, resource_type, resource_id, blocked_user_id)
                 VALUES (:owner, :type, :resource_id, :blocked)',
                [
                    'owner' => $ownerUserId,
                    'type' => $resourceType,
                    'resource_id' => $resourceId,
                    'blocked' => $blockedUserId,
                ]
            );
        }

        $this->db->audit($actorUserId, 'replace_blocks', 'visibility_blocks', $resourceId, [
            'resource_type' => $resourceType,
            'blocked_user_ids' => array_values($unique),
        ]);
    }

    /**
     * True when the viewer should not see this resource at all.
     */
    public function isHiddenFromViewer(
        int $ownerUserId,
        int $viewerUserId,
        bool $isPrivate,
        string $resourceType,
        ?int $resourceId
    ): bool {
        if ($viewerUserId === $ownerUserId) {
            return false;
        }
        if ($isPrivate) {
            return true;
        }
        if ($resourceId === null) {
            return false;
        }
        return $this->isBlocked($resourceType, $resourceId, $viewerUserId);
    }
}
