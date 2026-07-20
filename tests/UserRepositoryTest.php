<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use InvalidArgumentException;
use NexWaypoint\Users\UserRepository;

final class UserRepositoryTest extends NexWaypointTestCase
{
    public function testSetDottedManagersPersistsAndSkipsSolidLine(): void
    {
        $boss = $this->insertUser('boss');
        $matrix = $this->insertUser('matrix');
        $worker = $this->insertUser('worker', $boss);
        $repo = new UserRepository($this->db, $this->logger);

        $repo->setDottedManagers($worker, [$boss, $matrix]);

        self::assertSame([$matrix], $repo->dottedManagerIds($worker));
        self::assertTrue($repo->hasDottedReport($matrix, $worker));
        self::assertFalse($repo->hasDottedReport($boss, $worker));
    }

    public function testReportingCycleIsRejected(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b', $a);
        $repo = new UserRepository($this->db, $this->logger);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');
        $repo->updateProfile($a, 'A', $b, true, false);
    }
}
