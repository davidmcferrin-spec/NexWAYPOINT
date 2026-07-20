<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Visibility\VisibilityBlockRepository;

final class VisibilityBlockRepositoryTest extends NexWaypointTestCase
{
    public function testReplaceAndDetectBlocks(): void
    {
        $ownerId = $this->insertUser('owner', null, 'manager');
        $viewerId = $this->insertUser('viewer', $ownerId, 'subordinate');
        $otherId = $this->insertUser('other', $ownerId, 'subordinate');
        $repo = new VisibilityBlockRepository($this->db);

        $repo->replaceBlocks($ownerId, VisibilityBlockRepository::TYPE_TRIP, 42, [$viewerId, $otherId]);

        self::assertTrue($repo->isBlocked(VisibilityBlockRepository::TYPE_TRIP, 42, $viewerId));
        self::assertSame([$viewerId, $otherId], $repo->blockedUserIds(VisibilityBlockRepository::TYPE_TRIP, 42));
        self::assertTrue($repo->isHiddenFromViewer($ownerId, $viewerId, false, VisibilityBlockRepository::TYPE_TRIP, 42));
        self::assertFalse($repo->isHiddenFromViewer($ownerId, $ownerId, true, VisibilityBlockRepository::TYPE_TRIP, 42));
        self::assertTrue($repo->isHiddenFromViewer($ownerId, $viewerId, true, VisibilityBlockRepository::TYPE_TRIP, 42));
    }
}
