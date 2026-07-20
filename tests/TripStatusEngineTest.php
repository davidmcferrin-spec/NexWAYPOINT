<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Trips\TripStatusEngine;

final class TripStatusEngineTest extends NexWaypointTestCase
{
    private function makeTrip(TripRepository $repo, int $ownerId, string $start, string $end): Trip
    {
        return $repo->create(new Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Chicago',
            startDate: $start,
            endDate: $end,
            status: 'active',
            tripPurpose: 'Broadcast setup',
            notes: null,
            isPrivate: false,
        ));
    }

    private function makeSegment(TripRepository $repo, int $tripId, array $overrides = []): TripSegment
    {
        $defaults = [
            'id' => null,
            'tripId' => $tripId,
            'segmentType' => 'flight',
            'segmentSubtype' => null,
            'carrierId' => null,
            'carrier' => 'United',
            'flightNumber' => '100',
            'confirmationCode' => 'XYZ789',
            'origin' => 'ORD',
            'destination' => 'LAX',
            'departDt' => null,
            'arriveDt' => null,
            'hotelStayId' => null,
            'status' => 'scheduled',
            'sourceParseLogId' => null,
        ];
        $merged = array_merge($defaults, $overrides);
        return $repo->addSegment(new TripSegment(...$merged));
    }

    public function testDefaultsToHomeWithNoActivity(): void
    {
        $userId = $this->insertUser('dave');
        $engine = new TripStatusEngine(new TripRepository($this->db, $this->logger), $this->logger);

        $result = $engine->resolveForUser($userId);

        self::assertSame('home', $result['status']);
    }

    public function testInFlightWhenNowIsWithinDepartArriveWindow(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $now = new \DateTimeImmutable('2026-08-01 14:00:00');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, $trip->id, [
            'departDt' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
            'arriveDt' => $now->modify('+1 hour')->format('Y-m-d H:i:s'),
            'status' => 'en_route',
        ]);

        $engine = new TripStatusEngine($tripRepo, $this->logger);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('en_route', $result['status']);
        self::assertStringContainsString('In Flight', $result['label']);
    }

    public function testLayoverBetweenTwoSegments(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $now = new \DateTimeImmutable('2026-08-01 12:00:00');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'ORD', 'destination' => 'DEN',
            'departDt' => $now->modify('-3 hours')->format('Y-m-d H:i:s'),
            'arriveDt' => $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
            'status' => 'landed',
        ]);
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'DEN', 'destination' => 'LAX',
            'departDt' => $now->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'arriveDt' => $now->modify('+3 hours')->format('Y-m-d H:i:s'),
            'status' => 'scheduled',
        ]);

        $engine = new TripStatusEngine($tripRepo, $this->logger);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('layover', $result['status']);
        self::assertStringContainsString('DEN', $result['label']);
    }

    public function testManualOverrideUsedWhenNoActiveTravel(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $today = new \DateTimeImmutable('today');

        $tripRepo->setLatestUserStatus($userId, 'office', 'Working from hotel this week');

        $engine = new TripStatusEngine($tripRepo, $this->logger);
        $result = $engine->resolveForUser($userId, $today);

        self::assertSame('office', $result['status']);
        self::assertSame('Working from hotel this week', $result['detail']['note']);
        self::assertTrue($result['detail']['override']);
    }

    public function testRemoteOverrideIncludesLocationInLabel(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $today = new \DateTimeImmutable('today');

        $tripRepo->setStatusOverride(
            $userId,
            'remote',
            null,
            $today->format('Y-m-d'),
            $userId,
            null,
            'Denver',
            'CO',
        );

        $engine = new TripStatusEngine($tripRepo, $this->logger);
        $result = $engine->resolveForUser($userId, $today);

        self::assertSame('remote', $result['status']);
        self::assertSame('Working Remote · Denver, CO', $result['label']);
    }

    public function testManualOverrideHonorsExpiryDate(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = new \DateTimeImmutable('2026-07-20');
        $mid = new \DateTimeImmutable('2026-07-22');
        $after = new \DateTimeImmutable('2026-07-24');

        $tripRepo->setStatusOverride(
            $userId,
            'unavailable',
            'PTO',
            '2026-07-23',
            $userId,
            '2026-07-20',
        );

        $engine = new TripStatusEngine($tripRepo, $this->logger);
        self::assertSame('unavailable', $engine->resolveForUser($userId, $start)['status']);
        self::assertSame('unavailable', $engine->resolveForUser($userId, $mid)['status']);
        self::assertSame('home', $engine->resolveForUser($userId, $after)['status']);
    }

    public function testClearStatusOverrideEndsEarly(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $today = new \DateTimeImmutable('today');

        $tripRepo->setStatusOverride(
            $userId,
            'office',
            null,
            $today->modify('+5 days')->format('Y-m-d'),
            $userId,
        );
        $engine = new TripStatusEngine($tripRepo, $this->logger);
        self::assertSame('office', $engine->resolveForUser($userId, $today)['status']);

        $tripRepo->clearStatusOverride($userId, $userId, $today);
        self::assertSame('home', $engine->resolveForUser($userId, $today)['status']);
    }
}
