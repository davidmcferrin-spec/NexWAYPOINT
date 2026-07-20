<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\NotificationRepository;

final class NotificationRepositoryTest extends NexWaypointTestCase
{
    public function testMarkReadClearsUnreadCount(): void
    {
        $userId = $this->insertUser('dave');
        $otherId = $this->insertUser('sara');
        $repo = new NotificationRepository($this->db);

        $a = $repo->create($userId, null, 'hotel_import', 'Stay imported');
        $b = $repo->create($userId, null, 'delay', 'ORD -> DCA delayed 45 minutes');
        $repo->create($otherId, null, 'hotel_import', 'Someone else');

        self::assertSame(2, $repo->unreadCount($userId));

        self::assertTrue($repo->markReadForUser($userId, $a));
        self::assertSame(1, $repo->unreadCount($userId));

        $otherAlert = $repo->create($otherId, null, 'landed', 'Landed: AUS -> ORD');
        self::assertFalse($repo->markReadForUser($userId, $otherAlert));
        self::assertSame(1, $repo->unreadCount($userId));

        $cleared = $repo->markAllReadForUser($userId);
        self::assertSame(1, $cleared);
        self::assertSame(0, $repo->unreadCount($userId));

        $row = $repo->find($b);
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['is_read']);
    }

    public function testFindForUserOrdersUnreadFirst(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new NotificationRepository($this->db);
        $first = $repo->create($userId, null, 'hotel_import', 'Older');
        $second = $repo->create($userId, null, 'trip_import', 'Newer');
        $repo->markReadForUser($userId, $second);

        $all = $repo->findForUser($userId, false, 10);
        self::assertCount(2, $all);
        self::assertSame($first, (int) $all[0]['id']);
        self::assertSame(0, (int) $all[0]['is_read']);
    }
}
