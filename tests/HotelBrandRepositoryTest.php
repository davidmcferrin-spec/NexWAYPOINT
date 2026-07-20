<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\HotelBrandRepository;

final class HotelBrandRepositoryTest extends NexWaypointTestCase
{
    public function testSeedsTopFiveDefaults(): void
    {
        $repo = new HotelBrandRepository($this->db, $this->logger);
        $names = $repo->namesForSelect();
        self::assertSame(
            ['Marriott', 'Hilton', 'IHG', 'Hyatt', 'Choice Hotels'],
            $names
        );
    }

    public function testAddAndRemove(): void
    {
        $userId = $this->insertUser('mgr', null, 'manager');
        $repo = new HotelBrandRepository($this->db, $this->logger);

        $created = $repo->create('Wyndham', $userId);
        self::assertNotNull($created->id);
        self::assertContains('Wyndham', $repo->namesForSelect());

        $repo->delete((int) $created->id, $userId);
        self::assertNotContains('Wyndham', $repo->namesForSelect());

        // Legacy property brand still appears when editing
        $names = $repo->namesForSelect('Wyndham');
        self::assertContains('Wyndham', $names);
    }
}
