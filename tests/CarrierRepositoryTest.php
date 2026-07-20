<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\Carrier;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

final class CarrierRepositoryTest extends NexWaypointTestCase
{
    public function testCreateAndReuseByIata(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new CarrierRepository($this->db, $this->logger);

        $created = $repo->create(new Carrier(null, $userId, 'Delta Air Lines', 'DL'));
        self::assertNotNull($created->id);
        self::assertSame('DL', $created->iataCode);

        $this->expectException(\InvalidArgumentException::class);
        $repo->create(new Carrier(null, $userId, 'Delta Duplicate', 'DL'));
    }

    public function testFlightIdentCombinesIataAndNumber(): void
    {
        $carrier = new Carrier(1, 1, 'United', 'UA');
        self::assertSame('UA100', $carrier->flightIdent('100'));
        self::assertSame('UA100', $carrier->flightIdent('UA100'));
    }

    public function testSegmentStoresCarrierIdAndDisplayName(): void
    {
        $userId = $this->insertUser('dave');
        $carriers = new CarrierRepository($this->db, $this->logger);
        $trips = new TripRepository($this->db, $this->logger);

        $carrier = $carriers->create(new Carrier(null, $userId, 'American Airlines', 'AA'));
        $trip = $trips->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'DFW',
            startDate: '2026-09-01',
            endDate: '2026-09-01',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $segment = $trips->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: (int) $carrier->id,
            carrier: $carrier->name,
            flightNumber: '1234',
            confirmationCode: null,
            origin: 'ORD',
            destination: 'DFW',
            departDt: '2026-09-01 10:00:00',
            arriveDt: null,
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        self::assertSame($carrier->id, $segment->carrierId);
        self::assertSame('American Airlines', $segment->carrier);
        self::assertSame('AA1234', $carrier->flightIdent((string) $segment->flightNumber));
    }

    public function testUpdateIata(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new CarrierRepository($this->db, $this->logger);
        $created = $repo->create(new Carrier(null, $userId, 'Southwest', 'WN'));
        $updated = $repo->update(new Carrier($created->id, $userId, 'Southwest Airlines', 'WN'), $userId);
        self::assertSame('Southwest Airlines', $updated->name);
    }
}
