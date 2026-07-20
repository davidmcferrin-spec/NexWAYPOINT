<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\OfficeVenueRepository;

final class OfficeVenueRepositoryTest extends NexWaypointTestCase
{
    public function testCreateUpdateAndDeactivate(): void
    {
        $userId = $this->insertUser('admin', null, 'manager');
        $repo = new OfficeVenueRepository($this->db, $this->logger);

        $created = $repo->create(
            'NewsNation Chicago',
            '1 N State St',
            'Chicago',
            'IL',
            '60602',
            'USA',
            'Bureau',
            41.8827,
            -87.6278,
            $userId,
        );

        self::assertNotNull($created->id);
        self::assertSame(['NewsNation Chicago'], $repo->namesForSelect());
        self::assertSame('1 N State St, Chicago, IL, 60602', $created->placeLabel());

        $updated = $repo->update(
            (int) $created->id,
            'NewsNation Chicago bureau',
            '233 N Michigan Ave',
            'Chicago',
            'IL',
            '60601',
            'USA',
            null,
            true,
            41.886,
            -87.623,
            $userId,
        );
        self::assertSame('NewsNation Chicago bureau', $updated->name);
        self::assertContains('NewsNation Chicago bureau', $repo->namesForSelect());

        $repo->deactivate((int) $created->id, $userId);
        self::assertSame([], $repo->namesForSelect());
        $again = $repo->find((int) $created->id);
        self::assertNotNull($again);
        self::assertFalse($again->isActive);
    }

    public function testDuplicateNameRejected(): void
    {
        $repo = new OfficeVenueRepository($this->db, $this->logger);
        $repo->create('Bureau', null, 'Chicago', 'IL', null, 'USA', null, null, null);

        $this->expectException(\InvalidArgumentException::class);
        $repo->create('bureau', null, 'NYC', 'NY', null, 'USA', null, null, null);
    }
}
