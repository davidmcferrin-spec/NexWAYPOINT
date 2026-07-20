<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * Per-user "do not book" preferences on global hotel_properties.
 */
final class UserHotelBlacklistRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function tableReady(): bool
    {
        return $this->db->tableExists('user_hotel_blacklist');
    }

    public function isBlacklisted(int $userId, int $propertyId): bool
    {
        if (!$this->tableReady()) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT id FROM user_hotel_blacklist
             WHERE user_id = :u AND hotel_property_id = :p LIMIT 1',
            ['u' => $userId, 'p' => $propertyId]
        );
        return $row !== null;
    }

    public function reason(int $userId, int $propertyId): ?string
    {
        if (!$this->tableReady()) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT reason FROM user_hotel_blacklist
             WHERE user_id = :u AND hotel_property_id = :p LIMIT 1',
            ['u' => $userId, 'p' => $propertyId]
        );
        return $row === null ? null : ($row['reason'] ?? null);
    }

    /**
     * @return array<int, true> property_id => true
     */
    public function propertyIdsForUser(int $userId): array
    {
        if (!$this->tableReady()) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT hotel_property_id FROM user_hotel_blacklist WHERE user_id = :u',
            ['u' => $userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['hotel_property_id']] = true;
        }
        return $out;
    }

    public function set(int $userId, int $propertyId, bool $blacklisted, ?string $reason = null, ?int $actorUserId = null): void
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('user_hotel_blacklist missing; run php scripts/migrate.php');
        }

        if ($blacklisted) {
            $reason = $reason !== null ? trim($reason) : '';
            if ($reason === '') {
                throw new \InvalidArgumentException('blacklist_reason is required when blacklisted.');
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM user_hotel_blacklist WHERE user_id = :u AND hotel_property_id = :p LIMIT 1',
                ['u' => $userId, 'p' => $propertyId]
            );
            if ($existing === null) {
                $this->db->execute(
                    'INSERT INTO user_hotel_blacklist (user_id, hotel_property_id, reason)
                     VALUES (:u, :p, :r)',
                    ['u' => $userId, 'p' => $propertyId, 'r' => $reason]
                );
                $this->db->audit($actorUserId ?? $userId, 'create', 'user_hotel_blacklist', $this->db->lastInsertId(), [
                    'hotel_property_id' => $propertyId,
                ]);
            } else {
                $this->db->execute(
                    'UPDATE user_hotel_blacklist SET reason = :r, updated_at = CURRENT_TIMESTAMP
                     WHERE user_id = :u AND hotel_property_id = :p',
                    ['r' => $reason, 'u' => $userId, 'p' => $propertyId]
                );
                $this->db->audit($actorUserId ?? $userId, 'update', 'user_hotel_blacklist', (int) $existing['id'], [
                    'hotel_property_id' => $propertyId,
                ]);
            }
            $this->logger->info('User hotel blacklist set', ['user_id' => $userId, 'property_id' => $propertyId]);
            return;
        }

        $this->db->execute(
            'DELETE FROM user_hotel_blacklist WHERE user_id = :u AND hotel_property_id = :p',
            ['u' => $userId, 'p' => $propertyId]
        );
        $this->db->audit($actorUserId ?? $userId, 'delete', 'user_hotel_blacklist', $propertyId, [
            'hotel_property_id' => $propertyId,
        ]);
        $this->logger->info('User hotel blacklist cleared', ['user_id' => $userId, 'property_id' => $propertyId]);
    }

    public function findMatchingForUser(int $userId, string $hotelName, ?string $city): ?array
    {
        if (!$this->tableReady()) {
            return null;
        }
        $sql = 'SELECT b.reason, hp.id AS property_id, hp.hotel_name, hp.city
                FROM user_hotel_blacklist b
                INNER JOIN hotel_properties hp ON hp.id = b.hotel_property_id
                WHERE b.user_id = :u AND LOWER(hp.hotel_name) = LOWER(:name)';
        $params = ['u' => $userId, 'name' => trim($hotelName)];
        if ($city !== null && trim($city) !== '') {
            $sql .= ' AND LOWER(COALESCE(hp.city, \'\')) = LOWER(:city)';
            $params['city'] = trim($city);
        }
        $sql .= ' LIMIT 1';
        return $this->db->fetchOne($sql, $params);
    }
}
