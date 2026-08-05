<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

final class CalendarFeedRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function findByToken(string $token): ?CalendarFeed
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM calendar_feeds WHERE token = :token',
            ['token' => $token]
        );
        return $row !== null ? CalendarFeed::fromRow($row) : null;
    }

    public function findForOwner(int $ownerUserId, string $kind): ?CalendarFeed
    {
        $this->assertKind($kind);
        $row = $this->db->fetchOne(
            'SELECT * FROM calendar_feeds WHERE owner_user_id = :owner AND kind = :kind',
            ['owner' => $ownerUserId, 'kind' => $kind]
        );
        return $row !== null ? CalendarFeed::fromRow($row) : null;
    }

    /**
     * Create the feed if missing; return existing otherwise.
     */
    public function ensureForOwner(int $ownerUserId, string $kind, ?int $actorUserId = null): CalendarFeed
    {
        $existing = $this->findForOwner($ownerUserId, $kind);
        if ($existing !== null) {
            return $existing;
        }
        return $this->create($ownerUserId, $kind, null, $actorUserId);
    }

    /**
     * @param list<int>|null $memberUserIds
     */
    public function create(
        int $ownerUserId,
        string $kind,
        ?array $memberUserIds = null,
        ?int $actorUserId = null,
    ): CalendarFeed {
        $this->assertKind($kind);
        $token = $this->generateToken();
        $membersJson = $kind === CalendarFeed::KIND_TEAM && $memberUserIds !== null
            ? json_encode(array_values(array_unique(array_map('intval', $memberUserIds))))
            : null;

        $this->db->execute(
            'INSERT INTO calendar_feeds (owner_user_id, kind, token, member_user_ids)
             VALUES (:owner, :kind, :token, :members)',
            [
                'owner' => $ownerUserId,
                'kind' => $kind,
                'token' => $token,
                'members' => $membersJson,
            ]
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'calendar_feeds', $id, [
            'kind' => $kind,
            'owner_user_id' => $ownerUserId,
        ]);
        $this->logger?->info('Calendar feed created', ['id' => $id, 'kind' => $kind, 'owner' => $ownerUserId]);

        $feed = $this->findById($id);
        if ($feed === null) {
            throw new \RuntimeException('Calendar feed insert succeeded but row could not be re-read.');
        }
        return $feed;
    }

    public function rotateToken(int $feedId, int $ownerUserId, ?int $actorUserId = null): CalendarFeed
    {
        $feed = $this->findById($feedId);
        if ($feed === null || $feed->ownerUserId !== $ownerUserId) {
            throw new \InvalidArgumentException('Calendar feed not found.');
        }
        $token = $this->generateToken();
        $this->db->execute(
            'UPDATE calendar_feeds SET token = :token, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['token' => $token, 'id' => $feedId]
        );
        $this->db->audit($actorUserId, 'rotate_token', 'calendar_feeds', $feedId, [
            'kind' => $feed->kind,
        ]);

        $updated = $this->findById($feedId);
        if ($updated === null) {
            throw new \RuntimeException('Calendar feed rotate succeeded but row could not be re-read.');
        }
        return $updated;
    }

    /**
     * @param list<int> $memberUserIds
     */
    public function setMembers(int $feedId, int $ownerUserId, array $memberUserIds, ?int $actorUserId = null): CalendarFeed
    {
        $feed = $this->findById($feedId);
        if ($feed === null || $feed->ownerUserId !== $ownerUserId) {
            throw new \InvalidArgumentException('Calendar feed not found.');
        }
        if ($feed->kind !== CalendarFeed::KIND_TEAM) {
            throw new \InvalidArgumentException('Only team feeds have member lists.');
        }

        $unique = [];
        foreach ($memberUserIds as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $ownerUserId) {
                $unique[$id] = $id;
            }
        }
        $list = array_values($unique);
        // Empty selection is stored as [] (nobody); null means "all teammates".
        // Settings UI always posts an explicit list, so we store JSON array.
        $json = json_encode($list);

        $this->db->execute(
            'UPDATE calendar_feeds SET member_user_ids = :members, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['members' => $json, 'id' => $feedId]
        );
        $this->db->audit($actorUserId, 'set_members', 'calendar_feeds', $feedId, [
            'member_count' => count($list),
        ]);

        $updated = $this->findById($feedId);
        if ($updated === null) {
            throw new \RuntimeException('Calendar feed member update succeeded but row could not be re-read.');
        }
        return $updated;
    }

    /**
     * Reset team members to "all other active users" (NULL).
     */
    public function clearMemberSelection(int $feedId, int $ownerUserId, ?int $actorUserId = null): CalendarFeed
    {
        $feed = $this->findById($feedId);
        if ($feed === null || $feed->ownerUserId !== $ownerUserId) {
            throw new \InvalidArgumentException('Calendar feed not found.');
        }
        if ($feed->kind !== CalendarFeed::KIND_TEAM) {
            throw new \InvalidArgumentException('Only team feeds have member lists.');
        }

        $this->db->execute(
            'UPDATE calendar_feeds SET member_user_ids = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $feedId]
        );
        $this->db->audit($actorUserId, 'clear_members', 'calendar_feeds', $feedId, []);

        $updated = $this->findById($feedId);
        if ($updated === null) {
            throw new \RuntimeException('Calendar feed clear members succeeded but row could not be re-read.');
        }
        return $updated;
    }

    public function touchAccess(int $feedId): void
    {
        $this->db->execute(
            'UPDATE calendar_feeds SET last_accessed_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $feedId]
        );
    }

    public function findById(int $id): ?CalendarFeed
    {
        $row = $this->db->fetchOne('SELECT * FROM calendar_feeds WHERE id = :id', ['id' => $id]);
        return $row !== null ? CalendarFeed::fromRow($row) : null;
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, [CalendarFeed::KIND_PERSONAL, CalendarFeed::KIND_TEAM], true)) {
            throw new \InvalidArgumentException("Invalid calendar feed kind '{$kind}'.");
        }
    }
}
