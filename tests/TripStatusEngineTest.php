<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Trips\TripStatusEngine;

final class TripStatusEngineTest extends NexWaypointTestCase
{
    private function engine(TripRepository $tripRepo): TripStatusEngine
    {
        return new TripStatusEngine($tripRepo, $this->logger, new AirportRepository($this->db, $this->logger));
    }

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
        $engine = $this->engine(new TripRepository($this->db, $this->logger));

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

        $engine = $this->engine($tripRepo);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('en_route', $result['status']);
        self::assertStringContainsString('In Flight', $result['label']);
    }

    public function testPreFlightWithin45MinutesOfDepart(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $depart = new \DateTimeImmutable('2026-08-01 10:00:00');
        $now = $depart->modify('-30 minutes');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'HSV',
            'destination' => 'DEN',
            'departDt' => $depart->format('Y-m-d H:i:s'),
            'arriveDt' => $depart->modify('+3 hours')->format('Y-m-d H:i:s'),
        ]);

        $engine = $this->engine($tripRepo);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('pre_flight', $result['status']);
        self::assertStringContainsString('Pre-flight', $result['label']);
        self::assertStringContainsString('HSV', $result['label']);
    }

    public function testPostFlightWithin45MinutesOfArrive(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $arrive = new \DateTimeImmutable('2026-08-01 13:00:00');
        $now = $arrive->modify('+20 minutes');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'HSV',
            'destination' => 'DEN',
            'departDt' => $arrive->modify('-3 hours')->format('Y-m-d H:i:s'),
            'arriveDt' => $arrive->format('Y-m-d H:i:s'),
            'status' => 'landed',
        ]);

        $engine = $this->engine($tripRepo);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('post_flight', $result['status']);
        self::assertStringContainsString('DEN', $result['label']);
    }

    public function testLayoverBetweenTwoSegments(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        // Arrive 10:00, next depart 12:00 (2h gap ≤ 3h). After post-flight ends at 10:45.
        $now = new \DateTimeImmutable('2026-08-01 11:00:00');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'ORD', 'destination' => 'DEN',
            'departDt' => '2026-08-01 08:00:00',
            'arriveDt' => '2026-08-01 10:00:00',
            'status' => 'landed',
        ]);
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'DEN', 'destination' => 'LAX',
            'departDt' => '2026-08-01 12:00:00',
            'arriveDt' => '2026-08-01 14:00:00',
            'status' => 'scheduled',
        ]);

        $engine = $this->engine($tripRepo);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('layover', $result['status']);
        self::assertStringContainsString('DEN', $result['label']);
    }

    public function testLongGapBecomesRemoteAtCity(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        // Arrive 10:00, next depart 16:00 (6h > 3h). Mid-gap after post-flight.
        $now = new \DateTimeImmutable('2026-08-01 12:00:00');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'HSV', 'destination' => 'DEN',
            'departDt' => '2026-08-01 07:00:00',
            'arriveDt' => '2026-08-01 10:00:00',
            'status' => 'landed',
        ]);
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'DEN', 'destination' => 'HSV',
            'departDt' => '2026-08-01 16:00:00',
            'arriveDt' => '2026-08-01 19:00:00',
            'status' => 'scheduled',
        ]);

        $engine = $this->engine($tripRepo);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('remote', $result['status']);
        self::assertStringContainsString('DEN', $result['label']);
        self::assertTrue($result['detail']['from_itinerary'] ?? false);
        self::assertSame('DEN', $result['detail']['location_city']);
    }

    public function testReturnHomeLegResumesEnRoute(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $now = new \DateTimeImmutable('2026-08-03 17:00:00');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-03');
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'HSV', 'destination' => 'DEN',
            'departDt' => '2026-08-01 08:00:00',
            'arriveDt' => '2026-08-01 10:00:00',
            'status' => 'landed',
        ]);
        $this->makeSegment($tripRepo, $trip->id, [
            'origin' => 'DEN', 'destination' => 'HSV',
            'departDt' => '2026-08-03 16:00:00',
            'arriveDt' => '2026-08-03 19:00:00',
            'status' => 'en_route',
        ]);

        $engine = $this->engine($tripRepo);
        $result = $engine->resolveForUser($userId, $now);

        self::assertSame('en_route', $result['status']);
        self::assertStringContainsString('DEN -> HSV', $result['label']);
    }

    public function testStatusEngineWithImportedMultiLegRoundTrip(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);

        $tripRepo->upsertItineraryByConfirmation($userId, 'IMP001', [
            [
                'segment_type' => 'flight',
                'carrier' => 'United',
                'flight_number' => '4821',
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-08-10 08:00:00',
                'arrive_dt' => '2026-08-10 10:00:00',
            ],
            [
                'segment_type' => 'flight',
                'carrier' => 'United',
                'flight_number' => '1630',
                'origin' => 'DEN',
                'destination' => 'LAX',
                'depart_dt' => '2026-08-10 12:00:00',
                'arrive_dt' => '2026-08-10 13:30:00',
            ],
            [
                'segment_type' => 'flight',
                'carrier' => 'United',
                'flight_number' => '200',
                'origin' => 'LAX',
                'destination' => 'HSV',
                'depart_dt' => '2026-08-14 16:00:00',
                'arrive_dt' => '2026-08-14 22:00:00',
            ],
        ], null, $userId);

        $engine = $this->engine($tripRepo);

        // Connection layover in DEN (≤3h) after post-flight window.
        $layover = $engine->resolveForUser($userId, new \DateTimeImmutable('2026-08-10 11:00:00'));
        self::assertSame('layover', $layover['status']);
        self::assertStringContainsString('DEN', $layover['label']);

        // Long gap at LAX before return → remote at city.
        $remote = $engine->resolveForUser($userId, new \DateTimeImmutable('2026-08-12 12:00:00'));
        self::assertSame('remote', $remote['status']);
        self::assertSame('LAX', $remote['detail']['location_city']);
        self::assertTrue($remote['detail']['from_itinerary']);
    }

    public function testHotelSegmentBeatsRemoteGap(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $props = new \NexWaypoint\Hotels\HotelPropertyRepository($this->db, $this->logger);
        $stays = new \NexWaypoint\Hotels\HotelStayRepository($this->db, $this->logger, $props);

        $property = $props->create(new \NexWaypoint\Hotels\HotelProperty(
            id: null,
            createdByUserId: $userId,
            hotelName: 'Denver Downtown',
            brand: null,
            addressLine1: null,
            addressLine2: null,
            city: 'Denver',
            stateRegion: 'CO',
            postalCode: null,
            country: null,
            phone: null,
            website: null,
            latitude: 39.74,
            longitude: -104.99,
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
            stayStart: '2026-08-10',
            stayEnd: '2026-08-12',
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: null,
            confirmationCode: null,
            wouldReturn: null,
            notes: null,
            isPrivate: false,
        ), $userId);

        $result = $tripRepo->upsertItineraryByConfirmation($userId, 'HTL001', [
            [
                'segment_type' => 'flight',
                'carrier' => 'United',
                'flight_number' => '1',
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-08-10 08:00:00',
                'arrive_dt' => '2026-08-10 10:00:00',
            ],
            [
                'segment_type' => 'flight',
                'carrier' => 'United',
                'flight_number' => '2',
                'origin' => 'DEN',
                'destination' => 'HSV',
                'depart_dt' => '2026-08-12 16:00:00',
                'arrive_dt' => '2026-08-12 19:00:00',
            ],
        ], null, $userId);

        $tripRepo->replaceTripHotels(
            (int) $result['trip']->id,
            [(int) $stay->id],
            $props,
            $stays,
            $userId
        );

        $engine = $this->engine($tripRepo);
        $mid = $engine->resolveForUser($userId, new \DateTimeImmutable('2026-08-11 12:00:00'));
        self::assertSame('at_hotel', $mid['status']);
        self::assertStringContainsString('Denver', $mid['label']);
        self::assertSame((int) $stay->id, $mid['detail']['hotel_stay_id']);
    }

    public function testTrainPostArrivalLabel(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $now = new \DateTimeImmutable('2026-08-01 12:20:00');

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, $trip->id, [
            'segmentType' => 'train',
            'carrier' => 'Amtrak',
            'flightNumber' => '90',
            'origin' => 'WAS',
            'destination' => 'NYP',
            'departDt' => '2026-08-01 08:00:00',
            'arriveDt' => '2026-08-01 12:00:00',
            'status' => 'landed',
        ]);

        $engine = $this->engine($tripRepo);
        $result = $engine->resolveForUser($userId, $now);
        self::assertSame('post_flight', $result['status']);
        self::assertStringContainsString('Post-arrival', $result['label']);
    }

    public function testManualOverrideUsedWhenNoActiveTravel(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $today = new \DateTimeImmutable('today');

        $tripRepo->setLatestUserStatus($userId, 'office', 'Working from hotel this week');

        $engine = $this->engine($tripRepo);
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

        $engine = $this->engine($tripRepo);
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

        $engine = $this->engine($tripRepo);
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
        $engine = $this->engine($tripRepo);
        self::assertSame('office', $engine->resolveForUser($userId, $today)['status']);

        $tripRepo->clearStatusOverride($userId, $userId, $today);
        self::assertSame('home', $engine->resolveForUser($userId, $today)['status']);
    }

    /**
     * HSV (Central) → DEN (Mountain): arrive "10:00" is mountain local = 11:00 central.
     * Naive APP_TIMEZONE compare would wrongly treat 10:30 CT as post-arrival.
     */
    public function testCrossTimezoneArrivalUsesDestinationAirport(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);

        $trip = $this->makeTrip($tripRepo, $userId, '2026-08-01', '2026-08-01');
        $this->makeSegment($tripRepo, (int) $trip->id, [
            'origin' => 'HSV',
            'destination' => 'DEN',
            'departDt' => '2026-08-01 08:00:00',
            'arriveDt' => '2026-08-01 10:00:00',
        ]);

        $engine = $this->engine($tripRepo);

        // 10:30 America/Chicago — still airborne until 11:00 CT (10:00 MT).
        $midFlight = new \DateTimeImmutable('2026-08-01 10:30:00', new \DateTimeZone('America/Chicago'));
        self::assertSame('en_route', $engine->resolveForUser($userId, $midFlight)['status']);

        // 11:15 America/Chicago — within 45m post-arrival window.
        $post = new \DateTimeImmutable('2026-08-01 11:15:00', new \DateTimeZone('America/Chicago'));
        self::assertSame('post_flight', $engine->resolveForUser($userId, $post)['status']);
    }

    public function testAirportRepositoryResolvesKnownIata(): void
    {
        $airports = new AirportRepository(null, $this->logger);
        self::assertSame('America/Chicago', $airports->timezoneForCode('hsv'));
        self::assertSame('America/Denver', $airports->timezoneForCode('DEN'));
        self::assertSame('America/New_York', $airports->timezoneForCode('DCA'));
        self::assertNull($airports->timezoneForCode('New York, NY'));
        self::assertNull($airports->timezoneForCode(null));
    }
}
