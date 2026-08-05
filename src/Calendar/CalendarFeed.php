<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

/**
 * Secret ICS subscription feed owned by one user. Kind is personal (own
 * itinerary) or team (visibility-filtered teammates). token is the raw
 * capability secret embedded in the subscribe URL.
 */
final class CalendarFeed
{
    public const KIND_PERSONAL = 'personal';
    public const KIND_TEAM = 'team';

    /**
     * @param list<int>|null $memberUserIds null = all other active users (team only)
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $ownerUserId,
        public readonly string $kind,
        public readonly string $token,
        public readonly ?array $memberUserIds,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $lastAccessedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $members = null;
        if (array_key_exists('member_user_ids', $row) && $row['member_user_ids'] !== null && $row['member_user_ids'] !== '') {
            $decoded = is_string($row['member_user_ids'])
                ? json_decode($row['member_user_ids'], true)
                : $row['member_user_ids'];
            if (is_array($decoded)) {
                $members = [];
                foreach ($decoded as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $members[] = $id;
                    }
                }
                $members = array_values(array_unique($members));
            }
        }

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            ownerUserId: (int) $row['owner_user_id'],
            kind: (string) $row['kind'],
            token: (string) $row['token'],
            memberUserIds: $members,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            lastAccessedAt: isset($row['last_accessed_at']) ? (string) $row['last_accessed_at'] : null,
        );
    }
}
