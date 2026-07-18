<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

final class VisibilityEngineTest extends NexWaypointTestCase
{
    private function makeEngine(): VisibilityEngine
    {
        $users = new UserRepository($this->db, $this->logger);
        $rules = new VisibilityRuleRepository($this->db);
        return new VisibilityEngine($users, $rules);
    }

    public function testSelfSeesAllFields(): void
    {
        $userId = $this->insertUser('dave');
        $engine = $this->makeEngine();

        $result = $engine->getVisibleFields($userId, $userId);

        self::assertSame(VisibilityEngine::DIRECTION_SELF, $result['direction']);
        self::assertSame(VisibilityEngine::ALL_FIELDS, $result['visible_fields']);
    }

    public function testTopDownDefaultsToCityAndDatesOnly(): void
    {
        $managerId = $this->insertUser('manager', null, 'manager');
        $subordinateId = $this->insertUser('sub', $managerId, 'subordinate');
        $engine = $this->makeEngine();

        // Manager viewing subordinate's data.
        $result = $engine->getVisibleFields($managerId, $subordinateId);

        self::assertSame(VisibilityEngine::DIRECTION_TOP_DOWN, $result['direction']);
        sort($result['visible_fields']);
        self::assertSame(['destination_city', 'travel_dates'], $result['visible_fields']);
    }

    public function testBottomUpDefaultsToAllFields(): void
    {
        $managerId = $this->insertUser('manager', null, 'manager');
        $subordinateId = $this->insertUser('sub', $managerId, 'subordinate');
        $engine = $this->makeEngine();

        // Subordinate viewing manager's data.
        $result = $engine->getVisibleFields($subordinateId, $managerId);

        self::assertSame(VisibilityEngine::DIRECTION_BOTTOM_UP, $result['direction']);
        self::assertEqualsCanonicalizing(VisibilityEngine::ALL_FIELDS, $result['visible_fields']);
    }

    public function testLateralDefaultsToAllFields(): void
    {
        $managerId = $this->insertUser('manager', null, 'manager');
        $peerAId = $this->insertUser('peerA', $managerId, 'peer');
        $peerBId = $this->insertUser('peerB', $managerId, 'peer');
        $engine = $this->makeEngine();

        $result = $engine->getVisibleFields($peerAId, $peerBId);

        self::assertSame(VisibilityEngine::DIRECTION_LATERAL, $result['direction']);
        self::assertEqualsCanonicalizing(VisibilityEngine::ALL_FIELDS, $result['visible_fields']);
    }

    public function testUnrelatedUsersGetRestrictedDefault(): void
    {
        $managerA = $this->insertUser('managerA', null, 'manager');
        $managerB = $this->insertUser('managerB', null, 'manager');
        $subA = $this->insertUser('subA', $managerA);
        $subB = $this->insertUser('subB', $managerB);
        $engine = $this->makeEngine();

        $result = $engine->getVisibleFields($subA, $subB);

        self::assertSame(VisibilityEngine::DIRECTION_UNRELATED, $result['direction']);
        sort($result['visible_fields']);
        self::assertSame(['destination_city', 'travel_dates'], $result['visible_fields']);
    }

    public function testPrivateTripHidesEverythingExceptSelf(): void
    {
        $managerId = $this->insertUser('manager', null, 'manager');
        $subordinateId = $this->insertUser('sub', $managerId, 'subordinate');
        $engine = $this->makeEngine();

        // Bottom-up would normally be "all fields" -- but the trip is private.
        $result = $engine->getVisibleFields($subordinateId, $managerId, tripIsPrivate: true);

        self::assertSame([], $result['visible_fields']);
    }

    public function testUserUserOverrideWinsOverDirectionDefault(): void
    {
        $managerId = $this->insertUser('manager', null, 'manager');
        $subordinateId = $this->insertUser('sub', $managerId, 'subordinate');
        $rules = new VisibilityRuleRepository($this->db);
        $engine = $this->makeEngine();

        // Subject (subordinate) grants their manager the flight_number field too,
        // on top of the top-down default of city+dates.
        $rules->upsert($subordinateId, $managerId, VisibilityEngine::DIRECTION_USER_USER, 'flight_number', true);

        $result = $engine->getVisibleFields($managerId, $subordinateId);

        self::assertTrue($result['overrides_applied']);
        self::assertContains('flight_number', $result['visible_fields']);
        self::assertContains('destination_city', $result['visible_fields']);
    }

    public function testDirectionWideOverrideAppliesToAllViewersInThatDirection(): void
    {
        $managerId = $this->insertUser('manager', null, 'manager');
        $subordinateId = $this->insertUser('sub', $managerId, 'subordinate');
        $rules = new VisibilityRuleRepository($this->db);
        $engine = $this->makeEngine();

        // Subordinate broadens the TOP_DOWN default to include trip_purpose for all managers.
        $rules->upsert($subordinateId, null, VisibilityEngine::DIRECTION_TOP_DOWN, 'trip_purpose', true);

        $result = $engine->getVisibleFields($managerId, $subordinateId);

        self::assertContains('trip_purpose', $result['visible_fields']);
    }
}
