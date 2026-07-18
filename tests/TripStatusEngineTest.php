<?php

declare(strict_types=1);

namespace NexWaypont\Tests;

use NexWaypont\Trips\Trip;
use NexWaypont\Trips\TripRepository;
use NexWaypont\Trips\TripSegment;
use NexWaypont\Trips\TripStatusEngine;

final class TripStatusEngineTest extends NexWaypontTestCase
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
            'carrier' => 'United',
            'flightNumber' => 'UA100',
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

        $tripRepo->setLatestUserStatus($userId, 'remote', 'Working from hotel this week');

        $engine = new TripStatusEngine($tripRepo, $this->logger);
        $result = $engine->resolveForUser($userId, $today);

        self::assertSame('remote', $result['status']);
        self::assertSame('Working from hotel this week', $result['detail']['note']);
    }
}
