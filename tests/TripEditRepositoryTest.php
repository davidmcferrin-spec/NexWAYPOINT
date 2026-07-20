<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

final class TripEditRepositoryTest extends NexWaypointTestCase
{
    public function testUpdateAndDeleteSegmentAndSyncDates(): void
    {
        $userId = $this->insertUser('traveler');
        $tripRepo = new TripRepository($this->db, $this->logger);

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver',
            startDate: '2026-08-01',
            endDate: '2026-08-01',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $leg1 = $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '100',
            confirmationCode: 'ABC',
            origin: 'HSV',
            destination: 'DEN',
            departDt: '2026-08-10 08:00:00',
            arriveDt: '2026-08-10 10:00:00',
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '200',
            confirmationCode: 'ABC',
            origin: 'DEN',
            destination: 'LAX',
            departDt: '2026-08-10 13:00:00',
            arriveDt: '2026-08-10 15:00:00',
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $synced = $tripRepo->syncTripDatesFromSegments((int) $trip->id, $userId);
        self::assertNotNull($synced);
        self::assertSame('2026-08-10', $synced->startDate);
        self::assertSame('2026-08-10', $synced->endDate);

        $updated = $tripRepo->updateSegment(new TripSegment(
            id: $leg1->id,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'United',
            flightNumber: '101',
            confirmationCode: 'ABC',
            origin: 'HSV',
            destination: 'DEN',
            departDt: '2026-08-09 08:00:00',
            arriveDt: '2026-08-09 10:00:00',
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ), $userId);

        self::assertSame('101', $updated->flightNumber);
        self::assertSame('United', $updated->carrier);

        $synced2 = $tripRepo->syncTripDatesFromSegments((int) $trip->id, $userId);
        self::assertNotNull($synced2);
        self::assertSame('2026-08-09', $synced2->startDate);
        self::assertSame('2026-08-10', $synced2->endDate);

        $tripRepo->deleteSegment((int) $leg1->id, $userId);
        $remaining = $tripRepo->segmentsForTrip((int) $trip->id);
        self::assertCount(1, $remaining);
        self::assertSame('200', $remaining[0]->flightNumber);
    }

    public function testReplaceTripLegsKeepsHotelAndRewritesFlights(): void
    {
        $userId = $this->insertUser('traveler');
        $tripRepo = new TripRepository($this->db, $this->logger);

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver',
            startDate: '2026-08-01',
            endDate: '2026-08-03',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '1',
            confirmationCode: 'OLD',
            origin: 'HSV',
            destination: 'DEN',
            departDt: '2026-08-01 08:00:00',
            arriveDt: '2026-08-01 10:00:00',
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'hotel',
            segmentSubtype: null,
            carrierId: null,
            carrier: null,
            flightNumber: null,
            confirmationCode: null,
            origin: 'DEN',
            destination: 'DEN',
            departDt: '2026-08-01 15:00:00',
            arriveDt: '2026-08-03 11:00:00',
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $tripRepo->replaceTripLegs((int) $trip->id, [
            [
                'segment_type' => 'flight',
                'carrier' => 'UA',
                'flight_number' => '100',
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-08-01 09:00:00',
                'arrive_dt' => '2026-08-01 11:00:00',
                'confirmation_code' => 'NEW1',
            ],
            [
                'segment_type' => 'flight',
                'carrier' => 'UA',
                'flight_number' => '200',
                'origin' => 'DEN',
                'destination' => 'HSV',
                'depart_dt' => '2026-08-03 16:00:00',
                'arrive_dt' => '2026-08-03 19:00:00',
                'confirmation_code' => 'NEW1',
            ],
        ], $userId);

        $segments = $tripRepo->segmentsForTrip((int) $trip->id);
        $types = array_map(static fn (TripSegment $s) => $s->segmentType, $segments);
        self::assertSame(['flight', 'hotel', 'flight'], $types);
        self::assertSame('100', $segments[0]->flightNumber);
        self::assertSame('200', $segments[2]->flightNumber);

        $synced = $tripRepo->find((int) $trip->id);
        self::assertNotNull($synced);
        self::assertSame('2026-08-01', $synced->startDate);
        self::assertSame('2026-08-03', $synced->endDate);
    }

    public function testRoundTripDestinationUsesOutboundPeak(): void
    {
        $userId = $this->insertUser('traveler');
        $trips = new TripRepository($this->db, $this->logger);

        $result = $trips->upsertItineraryByConfirmation($userId, 'ROUND1', [
            [
                'segment_type' => 'flight',
                'carrier' => 'UA',
                'flight_number' => '100',
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-09-01 08:00:00',
                'arrive_dt' => '2026-09-01 10:00:00',
            ],
            [
                'segment_type' => 'flight',
                'carrier' => 'UA',
                'flight_number' => '200',
                'origin' => 'DEN',
                'destination' => 'HSV',
                'depart_dt' => '2026-09-04 17:00:00',
                'arrive_dt' => '2026-09-04 20:00:00',
            ],
        ], null, $userId);

        self::assertSame('DEN', $result['trip']->destinationCity);

        $multi = $trips->upsertItineraryByConfirmation($userId, 'ROUND2', [
            [
                'origin' => 'HSV', 'destination' => 'ORD',
                'depart_dt' => '2026-10-01 08:00:00', 'arrive_dt' => '2026-10-01 10:00:00',
                'flight_number' => '1',
            ],
            [
                'origin' => 'ORD', 'destination' => 'LAX',
                'depart_dt' => '2026-10-01 12:00:00', 'arrive_dt' => '2026-10-01 15:00:00',
                'flight_number' => '2',
            ],
            [
                'origin' => 'LAX', 'destination' => 'ORD',
                'depart_dt' => '2026-10-05 09:00:00', 'arrive_dt' => '2026-10-05 15:00:00',
                'flight_number' => '3',
            ],
            [
                'origin' => 'ORD', 'destination' => 'HSV',
                'depart_dt' => '2026-10-05 17:00:00', 'arrive_dt' => '2026-10-05 19:00:00',
                'flight_number' => '4',
            ],
        ], null, $userId);

        // Midpoint of outbound half → LAX, not HSV home.
        self::assertSame('LAX', $multi['trip']->destinationCity);
    }

    public function testReplaceTripLegsMixedFlightAndTrain(): void
    {
        $userId = $this->insertUser('traveler');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $carriers = new \NexWaypoint\Trips\CarrierRepository($this->db, $this->logger);
        $airline = $carriers->findOrCreateByIata($userId, 'UA', 'United', $userId);
        $rail = $carriers->findOrCreateByName($userId, 'Amtrak', null, $userId, \NexWaypoint\Trips\Carrier::TYPE_RAIL);

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'New York',
            startDate: '2026-09-01',
            endDate: '2026-09-03',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $tripRepo->replaceTripLegs((int) $trip->id, [
            [
                'segment_type' => 'flight',
                'carrier_id' => $airline->id,
                'carrier' => $airline->name,
                'flight_number' => '100',
                'origin' => 'HSV',
                'destination' => 'DCA',
                'depart_dt' => '2026-09-01 08:00:00',
                'arrive_dt' => '2026-09-01 11:00:00',
            ],
            [
                'segment_type' => 'train',
                'carrier_id' => $rail->id,
                'carrier' => $rail->name,
                'flight_number' => '90',
                'origin' => 'WAS',
                'destination' => 'NYP',
                'depart_dt' => '2026-09-01 13:00:00',
                'arrive_dt' => '2026-09-01 16:00:00',
            ],
        ], $userId);

        $segments = $tripRepo->segmentsForTrip((int) $trip->id);
        self::assertCount(2, $segments);
        self::assertSame('flight', $segments[0]->segmentType);
        self::assertSame('train', $segments[1]->segmentType);
        self::assertSame('90', $segments[1]->flightNumber);
    }

    public function testReplaceTripHotelsLinksStay(): void
    {
        $userId = $this->insertUser('traveler');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $props = new \NexWaypoint\Hotels\HotelPropertyRepository($this->db, $this->logger);
        $stays = new \NexWaypoint\Hotels\HotelStayRepository($this->db, $this->logger, $props);

        $property = $props->create(new \NexWaypoint\Hotels\HotelProperty(
            id: null,
            createdByUserId: $userId,
            hotelName: 'Hilton Midtown',
            brand: 'Hilton',
            addressLine1: null,
            addressLine2: null,
            city: 'New York',
            stateRegion: 'NY',
            postalCode: null,
            country: null,
            phone: null,
            website: null,
            latitude: 40.75,
            longitude: -73.98,
            hasDesk: false,
            deskNotes: null,
            hasPool: false,
            hasHotTub: false,
            hasBreakfast: false,
            breakfastNotes: null,
            hasGym: false,
            hasFreeParking: false,
            hasAirportShuttle: false,
            hasEvCharging: false,
            hasOnsiteRestaurant: false,
            hasOffsiteGym: false,
            walkToOffice: false,
            walkToOfficeNotes: null,
            hasDestinationFee: false,
            destinationFeeNotes: null,
            wifiQuality: null,
            noiseLevel: null,
            uniqueFeatures: null,
        ), $userId);

        $stay = $stays->create(new \NexWaypoint\Hotels\HotelStay(
            id: null,
            userId: $userId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: '2026-09-01',
            stayEnd: '2026-09-03',
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: null,
            confirmationCode: null,
            wouldReturn: null,
            notes: null,
            isPrivate: false,
        ), $userId);

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'New York',
            startDate: '2026-09-01',
            endDate: '2026-09-03',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $tripRepo->replaceTripLegs((int) $trip->id, [[
            'segment_type' => 'flight',
            'carrier' => 'UA',
            'flight_number' => '1',
            'origin' => 'HSV',
            'destination' => 'LGA',
            'depart_dt' => '2026-09-01 08:00:00',
            'arrive_dt' => '2026-09-01 12:00:00',
        ]], $userId);

        $hotels = $tripRepo->replaceTripHotels(
            (int) $trip->id,
            [(int) $stay->id],
            $props,
            $stays,
            $userId
        );

        self::assertCount(1, $hotels);
        self::assertSame('hotel', $hotels[0]->segmentType);
        self::assertSame((int) $stay->id, $hotels[0]->hotelStayId);
        self::assertSame('Hilton Midtown', $hotels[0]->carrier);
        self::assertSame('New York', $hotels[0]->destination);
        self::assertSame([(int) $stay->id], $tripRepo->hotelStayIdsForTrip((int) $trip->id));

        // Transit legs still present.
        $all = $tripRepo->segmentsForTrip((int) $trip->id);
        self::assertCount(2, $all);
    }
}
