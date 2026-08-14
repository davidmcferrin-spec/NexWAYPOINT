<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
use NexWaypoint\Users\TeamLocationResolver;
use NexWaypoint\Users\TeamTravelPreviewBuilder;
use NexWaypoint\Users\TeamUpcomingTripFinder;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

final class TeamAvatarStatusTest extends NexWaypointTestCase
{
    public function testRemoteOverrideRequiresCity(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('City is required');
        $tripRepo->setStatusOverride(
            $userId,
            'remote',
            null,
            (new \DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d'),
            $userId,
        );
    }

    public function testRemoteLabelIncludesCityState(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $today = new \DateTimeImmutable('today');

        $tripRepo->setStatusOverride(
            $userId,
            'remote',
            'Client site',
            $today->modify('+3 days')->format('Y-m-d'),
            $userId,
            null,
            'Austin',
            'TX',
        );

        $engine = new TripStatusEngine($tripRepo, $this->logger, new \NexWaypoint\Trips\AirportRepository($this->db, $this->logger));
        $result = $engine->resolveForUser($userId, $today);

        self::assertSame('remote', $result['status']);
        self::assertSame('Working Remote · Austin, TX', $result['label']);
        self::assertSame('Austin', $result['detail']['location_city']);
        self::assertSame('TX', $result['detail']['location_state']);
    }

    public function testHomeLocationPinsFromProfileCoords(): void
    {
        $userId = $this->insertUser('dave');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $resolver = new TeamLocationResolver(
            new TripRepository($this->db, $this->logger),
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, sys_get_temp_dir() . '/nx_geocode_test'),
        );

        $pin = $resolver->resolve($user, ['status' => 'home', 'label' => 'Home', 'detail' => []]);
        self::assertNotNull($pin);
        self::assertSame(34.7304, $pin['lat']);
        self::assertSame(-86.5861, $pin['lon']);
        self::assertStringContainsString('Huntsville', $pin['city_label']);
    }

    public function testTravelPinOmittedWhenDestinationNotVisible(): void
    {
        $userId = $this->insertUser('dave');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $resolver = new TeamLocationResolver(
            new TripRepository($this->db, $this->logger),
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, sys_get_temp_dir() . '/nx_geocode_test'),
        );

        $pin = $resolver->resolve(
            $user,
            [
                'status' => 'en_route',
                'label' => 'In Flight: ORD -> LAX',
                'detail' => ['trip_id' => 1, 'destination' => 'LAX'],
            ],
            false,
        );
        self::assertNull($pin);
    }

    public function testPhotoFocusPersisted(): void
    {
        $userId = $this->insertUser('dave');
        $userRepo = new UserRepository($this->db, $this->logger);
        $path = sys_get_temp_dir() . '/avatar_test_' . $userId . '.jpg';
        file_put_contents($path, 'fake');

        $user = $userRepo->updatePhoto($userId, $path, 35.5, 62.25, $userId);
        self::assertSame($path, $user->photoPath);
        self::assertSame(35.5, $user->photoFocusX);
        self::assertSame(62.25, $user->photoFocusY);
        self::assertTrue($user->hasPhoto());

        @unlink($path);
    }

    public function testUpcomingLabelKeepsHomePinWhenAtBase(): void
    {
        $userId = $this->insertUser('dave');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = (new \DateTimeImmutable('today'))->modify('+5 days')->format('Y-m-d');
        $end = (new \DateTimeImmutable('today'))->modify('+8 days')->format('Y-m-d');
        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: $start,
            endDate: $end,
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $cacheDir = sys_get_temp_dir() . '/nx_geocode_upcoming_' . $userId;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheKey = strtolower('v3||Chicago|IL||United States');
        file_put_contents(
            $cacheDir . '/' . hash('sha256', $cacheKey) . '.json',
            json_encode(['lat' => 41.8781, 'lon' => -87.6298])
        );

        $resolver = new TeamLocationResolver(
            $tripRepo,
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, $cacheDir),
        );

        $result = $resolver->resolveWithUpcoming(
            $user,
            ['status' => 'home', 'label' => 'Home', 'detail' => []],
            true,
            $trip,
        );

        self::assertNotNull($result['location']);
        self::assertSame(34.7304, $result['location']['lat']);
        self::assertStringContainsString('Huntsville', $result['location']['city_label']);
        self::assertNotNull($result['next']);
        self::assertStringContainsString('Chicago', $result['next']['city_label']);
        self::assertNotSame('', $result['next']['dates']);
        self::assertStringContainsString('Chicago', (string) $result['upcoming']);
        self::assertStringContainsString('·', (string) $result['upcoming']);
    }

    public function testUpcomingIataDestinationUsesAirportLabel(): void
    {
        $userId = $this->insertUser('dave');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = (new \DateTimeImmutable('today'))->modify('+5 days')->format('Y-m-d');
        $end = (new \DateTimeImmutable('today'))->modify('+8 days')->format('Y-m-d');
        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'DFW',
            startDate: $start,
            endDate: $end,
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $cacheDir = sys_get_temp_dir() . '/nx_geocode_iata_' . $userId;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheKey = strtolower('v3||Dallas/Fort Worth|TX||United States');
        file_put_contents(
            $cacheDir . '/' . hash('sha256', $cacheKey) . '.json',
            json_encode(['lat' => 32.8998, 'lon' => -97.0403])
        );

        $resolver = new TeamLocationResolver(
            $tripRepo,
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, $cacheDir),
            new AirportRepository(null, $this->logger),
        );

        $result = $resolver->resolveWithUpcoming(
            $user,
            ['status' => 'home', 'label' => 'Home', 'detail' => []],
            true,
            $trip,
        );

        self::assertNotNull($result['next']);
        self::assertSame('Dallas/Fort Worth, TX (DFW)', $result['next']['city_label']);
        self::assertStringContainsString('Dallas/Fort Worth, TX (DFW)', (string) $result['upcoming']);
    }

    public function testPrivateUpcomingDoesNotMovePinWhenTripNull(): void
    {
        $userId = $this->insertUser('dave');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $resolver = new TeamLocationResolver(
            new TripRepository($this->db, $this->logger),
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, sys_get_temp_dir() . '/nx_geocode_test'),
        );

        // Visibility layer omitted the trip (null) → stay on home.
        $result = $resolver->resolveWithUpcoming(
            $user,
            ['status' => 'home', 'label' => 'Home', 'detail' => []],
            true,
            null,
        );

        self::assertNotNull($result['location']);
        self::assertSame(34.7304, $result['location']['lat']);
        self::assertNull($result['upcoming']);
    }

    public function testActiveTravelIgnoresUpcomingOverride(): void
    {
        $userId = $this->insertUser('dave');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'),
            endDate: (new \DateTimeImmutable('today'))->modify('+6 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $cacheDir = sys_get_temp_dir() . '/nx_geocode_active_' . $userId;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheKey = strtolower('v3||Chicago|IL||United States');
        file_put_contents(
            $cacheDir . '/' . hash('sha256', $cacheKey) . '.json',
            json_encode(['lat' => 41.8781, 'lon' => -87.6298])
        );

        $resolver = new TeamLocationResolver(
            $tripRepo,
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, $cacheDir),
        );

        $result = $resolver->resolveWithUpcoming(
            $user,
            [
                'status' => 'en_route',
                'label' => 'In Flight: HSV -> ORD',
                'detail' => ['trip_id' => $trip->id, 'destination' => 'ORD'],
            ],
            true,
            $trip,
        );

        // Active trip passed as "upcoming" must not become Next; no return leg → still empty.
        self::assertNull($result['upcoming']);
        self::assertNull($result['next']);
        self::assertTrue(TeamLocationResolver::isAtBaseStatus('home'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('en_route'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('pre_flight'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('post_flight'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('layover'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('remote', ['from_itinerary' => true]));
        self::assertTrue(TeamLocationResolver::isAtBaseStatus('remote'));
    }

    public function testTimeOfDayBucketBoundaries(): void
    {
        self::assertSame('Early', TeamLocationResolver::timeOfDayBucket('2026-08-14 10:00:00'));
        self::assertSame('Afternoon', TeamLocationResolver::timeOfDayBucket('2026-08-14 10:01:00'));
        self::assertSame('Afternoon', TeamLocationResolver::timeOfDayBucket('2026-08-14 16:00:00'));
        self::assertSame('Evening', TeamLocationResolver::timeOfDayBucket('2026-08-14 16:01:00'));
        self::assertSame('Evening', TeamLocationResolver::timeOfDayBucket('2026-08-14 20:00:00'));
        self::assertSame('Late', TeamLocationResolver::timeOfDayBucket('2026-08-14 20:01:00'));
        self::assertSame('Early', TeamLocationResolver::timeOfDayBucket('2026-08-14 06:30:00'));
        self::assertNull(TeamLocationResolver::timeOfDayBucket(null));
    }

    public function testAwayRoundTripShowsHomeNextWithTimeOfDay(): void
    {
        $userId = $this->insertUser('dave_home_next');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $out = new \DateTimeImmutable('2026-08-10');
        $back = new \DateTimeImmutable('2026-08-14');
        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Los Angeles, CA',
            startDate: $out->format('Y-m-d'),
            endDate: $back->format('Y-m-d'),
            status: 'active',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '1',
            confirmationCode: 'HOME1',
            origin: 'HSV',
            destination: 'LAX',
            departDt: $out->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $out->setTime(11, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '2',
            confirmationCode: 'HOME1',
            origin: 'LAX',
            destination: 'HSV',
            departDt: $back->setTime(16, 30)->format('Y-m-d H:i:s'),
            arriveDt: $back->setTime(22, 15)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $resolver = new TeamLocationResolver(
            $tripRepo,
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, sys_get_temp_dir() . '/nx_geocode_home_next'),
            new AirportRepository(null, $this->logger),
        );

        $result = $resolver->resolveWithUpcoming(
            $user,
            [
                'status' => 'at_hotel',
                'label' => 'At hotel in Los Angeles, CA',
                'detail' => ['trip_id' => $trip->id, 'destination' => 'Los Angeles, CA'],
            ],
            true,
            null,
            new \DateTimeImmutable('2026-08-12 12:00:00'),
        );

        self::assertNotNull($result['next']);
        self::assertSame('Home', $result['next']['city_label']);
        self::assertSame('Aug 14', $result['next']['dates']);
        self::assertSame('Late', $result['next']['time_of_day']);
        self::assertSame('Home · Aug 14 · Late', $result['upcoming']);
        self::assertSame('Aug 14 · Late', TeamLocationResolver::formatNextDatesHint($result['next']));
    }

    public function testHomeReturnBeatsLaterTripAsNext(): void
    {
        $userId = $this->insertUser('dave_vs_later');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $out = new \DateTimeImmutable('2026-08-10');
        $back = new \DateTimeImmutable('2026-08-14');
        $active = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver, CO',
            startDate: $out->format('Y-m-d'),
            endDate: $back->format('Y-m-d'),
            status: 'active',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $active->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '10',
            confirmationCode: 'DEN1',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $out->setTime(9, 0)->format('Y-m-d H:i:s'),
            arriveDt: $out->setTime(11, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $active->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '11',
            confirmationCode: 'DEN1',
            origin: 'DEN',
            destination: 'HSV',
            departDt: $back->setTime(15, 0)->format('Y-m-d H:i:s'),
            arriveDt: $back->setTime(18, 30)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $laterStart = new \DateTimeImmutable('2026-08-20');
        $later = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: $laterStart->format('Y-m-d'),
            endDate: $laterStart->modify('+2 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $cacheDir = sys_get_temp_dir() . '/nx_geocode_vs_later_' . $userId;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheKey = strtolower('v3||Chicago|IL||United States');
        file_put_contents(
            $cacheDir . '/' . hash('sha256', $cacheKey) . '.json',
            json_encode(['lat' => 41.8781, 'lon' => -87.6298])
        );

        $resolver = new TeamLocationResolver(
            $tripRepo,
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, $cacheDir),
            new AirportRepository(null, $this->logger),
        );

        $result = $resolver->resolveWithUpcoming(
            $user,
            [
                'status' => 'remote',
                'label' => 'Working Remote · Denver, CO',
                'detail' => [
                    'trip_id' => $active->id,
                    'location_city' => 'Denver, CO',
                    'from_itinerary' => true,
                ],
            ],
            true,
            $later,
            new \DateTimeImmutable('2026-08-12 12:00:00'),
        );

        self::assertNotNull($result['next']);
        self::assertSame('Home', $result['next']['city_label']);
        self::assertSame('Evening', $result['next']['time_of_day']);
        self::assertStringContainsString('Home', (string) $result['upcoming']);
        self::assertStringNotContainsString('Chicago', (string) $result['upcoming']);
    }

    public function testAtBaseUpcomingIncludesDepartTimeOfDay(): void
    {
        $userId = $this->insertUser('dave_tod_depart');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $day = (new \DateTimeImmutable('today'))->modify('+5 days');
        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: $day->format('Y-m-d'),
            endDate: $day->modify('+2 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'AA',
            flightNumber: '100',
            confirmationCode: 'TOD1',
            origin: 'HSV',
            destination: 'ORD',
            departDt: $day->setTime(7, 15)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(9, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $cacheDir = sys_get_temp_dir() . '/nx_geocode_tod_' . $userId;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheKey = strtolower('v3||Chicago|IL||United States');
        file_put_contents(
            $cacheDir . '/' . hash('sha256', $cacheKey) . '.json',
            json_encode(['lat' => 41.8781, 'lon' => -87.6298])
        );

        $resolver = new TeamLocationResolver(
            $tripRepo,
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, $cacheDir),
        );

        $result = $resolver->resolveWithUpcoming(
            $user,
            ['status' => 'home', 'label' => 'Home', 'detail' => []],
            true,
            $trip,
        );

        self::assertNotNull($result['next']);
        self::assertStringContainsString('Chicago', $result['next']['city_label']);
        self::assertSame('Early', $result['next']['time_of_day']);
        self::assertStringContainsString('Early', (string) $result['upcoming']);
    }

    public function testMultiCityNextIsFirstStayThenSecondCity(): void
    {
        $userId = $this->insertUser('dave_multicity');
        $userRepo = new UserRepository($this->db, $this->logger);
        $userRepo->updateHomeLocation($userId, 'Huntsville', 'AL', 34.7304, -86.5861, $userId);
        $user = $userRepo->find($userId);
        self::assertNotNull($user);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $imported = $tripRepo->upsertItineraryByConfirmation($userId, 'NTSHWH', [
            [
                'segment_type' => 'flight',
                'carrier' => 'American Airlines',
                'flight_number' => '3634',
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-24 07:00:00',
                'arrive_dt' => '2026-08-24 09:10:00',
            ],
            [
                'segment_type' => 'flight',
                'carrier' => 'American Airlines',
                'flight_number' => '1609',
                'origin' => 'DFW',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-26 07:34:00',
                'arrive_dt' => '2026-08-26 12:02:00',
            ],
        ], null, $userId);
        $trip = $imported['trip'];

        $resolver = new TeamLocationResolver(
            $tripRepo,
            new HotelStayRepository($this->db, $this->logger),
            new HotelPropertyRepository($this->db, $this->logger),
            new Geocoder($this->logger, sys_get_temp_dir() . '/nx_geocode_multicity'),
            new AirportRepository(null, $this->logger),
        );

        $atHome = $resolver->resolveWithUpcoming(
            $user,
            ['status' => 'home', 'label' => 'Home', 'detail' => []],
            true,
            $trip,
            new \DateTimeImmutable('2026-08-13 12:00:00'),
        );
        self::assertNotNull($atHome['next']);
        self::assertSame('Dallas/Fort Worth, TX (DFW)', $atHome['next']['city_label']);
        self::assertSame('Aug 24', $atHome['next']['dates']);
        self::assertSame('Early', $atHome['next']['time_of_day']);

        $inDallas = $resolver->resolveWithUpcoming(
            $user,
            [
                'status' => 'remote',
                'label' => 'Working Remote · Dallas/Fort Worth, TX',
                'detail' => [
                    'trip_id' => $trip->id,
                    'destination' => 'DFW',
                    'location_city' => 'Dallas/Fort Worth, TX',
                    'from_itinerary' => true,
                ],
            ],
            true,
            null,
            new \DateTimeImmutable('2026-08-25 12:00:00'),
        );
        self::assertNotNull($inDallas['next']);
        self::assertSame('New York, NY (LGA)', $inDallas['next']['city_label']);
        self::assertSame('Aug 26', $inDallas['next']['dates']);
        self::assertSame('Early', $inDallas['next']['time_of_day']);

        $inNewYork = $resolver->resolveWithUpcoming(
            $user,
            [
                'status' => 'remote',
                'label' => 'Working Remote · New York, NY',
                'detail' => [
                    'trip_id' => $trip->id,
                    'destination' => 'LGA',
                    'location_city' => 'New York, NY',
                    'from_itinerary' => true,
                ],
            ],
            true,
            null,
            new \DateTimeImmutable('2026-08-26 15:00:00'),
        );
        self::assertNull($inNewYork['next']);
    }

    public function testUpcomingFinderSkipsPrivateTripsForOthers(): void
    {
        $ownerId = $this->insertUser('owner');
        $viewerId = $this->insertUser('viewer');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = (new \DateTimeImmutable('today'))->modify('+4 days')->format('Y-m-d');
        $end = (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');

        $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Seattle, WA',
            startDate: $start,
            endDate: $end,
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: true,
        ));

        $finder = new TeamUpcomingTripFinder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
        );

        self::assertNull($finder->findVisible($viewerId, $ownerId, 21));
        self::assertNotNull($finder->findVisible($ownerId, $ownerId, 21));
    }

    public function testUpcomingFinderExcludesActiveTrip(): void
    {
        $ownerId = $this->insertUser('owner_ex');
        $tripRepo = new TripRepository($this->db, $this->logger);

        $current = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Washington, DC',
            startDate: (new \DateTimeImmutable('today'))->format('Y-m-d'),
            endDate: (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'),
            status: 'active',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $later = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Chicago, IL',
            startDate: (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'),
            endDate: (new \DateTimeImmutable('today'))->modify('+12 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $finder = new TeamUpcomingTripFinder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
        );

        $withoutExclude = $finder->findVisible($ownerId, $ownerId, 21);
        self::assertNotNull($withoutExclude);
        self::assertSame((int) $current->id, (int) $withoutExclude->id);

        $next = $finder->findVisible($ownerId, $ownerId, 21, (int) $current->id);
        self::assertNotNull($next);
        self::assertSame((int) $later->id, (int) $next->id);
        self::assertStringContainsString('Chicago', $next->destinationCity);
    }

    public function testTravelPreviewHidesPrivateTripsFromOthers(): void
    {
        $ownerId = $this->insertUser('owner2');
        $viewerId = $this->insertUser('viewer2');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = (new \DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');
        $end = (new \DateTimeImmutable('today'))->modify('+4 days')->format('Y-m-d');

        $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Denver, CO',
            startDate: $start,
            endDate: $end,
            status: 'planned',
            tripPurpose: 'Secret',
            notes: null,
            isPrivate: true,
        ));
        $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Atlanta, GA',
            startDate: $start,
            endDate: $end,
            status: 'planned',
            tripPurpose: 'Shoot',
            notes: null,
            isPrivate: false,
        ));

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
        );

        $forViewer = $builder->build($viewerId, $ownerId, 21);
        self::assertCount(1, $forViewer);
        self::assertSame('Atlanta, GA', $forViewer[0]['destination']);

        $forOwner = $builder->build($ownerId, $ownerId, 21);
        self::assertCount(2, $forOwner);
    }

    public function testTravelPreviewIncludesMultiLegAndLayover(): void
    {
        $ownerId = $this->insertUser('flyer');
        $viewerId = $this->insertUser('peer');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $day = (new \DateTimeImmutable('today'))->modify('+3 days');
        $start = $day->format('Y-m-d');
        $end = $day->format('Y-m-d');

        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Los Angeles, CA',
            startDate: $start,
            endDate: $end,
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'United',
            flightNumber: '100',
            confirmationCode: 'ABC123',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $day->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(10, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'United',
            flightNumber: '200',
            confirmationCode: 'ABC123',
            origin: 'DEN',
            destination: 'LAX',
            departDt: $day->setTime(12, 30)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(14, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
        );

        $preview = $builder->build($viewerId, $ownerId, 21);
        self::assertCount(1, $preview);
        $itin = $preview[0]['itinerary'];
        self::assertCount(3, $itin);
        self::assertSame('leg', $itin[0]['type']);
        self::assertStringContainsString('HSV', $itin[0]['label']);
        self::assertStringContainsString('DEN', $itin[0]['label']);
        self::assertSame('layover', $itin[1]['type']);
        self::assertStringContainsString('Layover in DEN', $itin[1]['label']);
        self::assertStringContainsString('2h 30m', $itin[1]['label']);
        self::assertSame('leg', $itin[2]['type']);
        self::assertStringContainsString('LAX', $itin[2]['label']);
    }

    public function testTravelPreviewLongGapIsStayNotLayover(): void
    {
        $ownerId = $this->insertUser('flyer2');
        $viewerId = $this->insertUser('peer2');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $day = (new \DateTimeImmutable('today'))->modify('+4 days');
        $start = $day->format('Y-m-d');

        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Denver, CO',
            startDate: $start,
            endDate: $start,
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'United',
            flightNumber: '10',
            confirmationCode: 'GAP001',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $day->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(10, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'United',
            flightNumber: '20',
            confirmationCode: 'GAP001',
            origin: 'DEN',
            destination: 'HSV',
            departDt: $day->setTime(16, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(19, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
        );

        $preview = $builder->build($viewerId, $ownerId, 21);
        $itin = $preview[0]['itinerary'];
        self::assertSame('stay', $itin[1]['type']);
        self::assertStringContainsString('In DEN', $itin[1]['label']);
        self::assertStringNotContainsString('Layover', $itin[1]['label']);
    }

    public function testTravelPreviewMultiCityListsStayCitiesInOrder(): void
    {
        $ownerId = $this->insertUser('flyer_mc');
        $viewerId = $this->insertUser('peer_mc');
        $tripRepo = new TripRepository($this->db, $this->logger);

        $tripRepo->upsertItineraryByConfirmation($ownerId, 'NTSHWH', [
            [
                'segment_type' => 'flight',
                'carrier' => 'American Airlines',
                'flight_number' => '3634',
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-24 07:00:00',
                'arrive_dt' => '2026-08-24 09:10:00',
            ],
            [
                'segment_type' => 'flight',
                'carrier' => 'American Airlines',
                'flight_number' => '1609',
                'origin' => 'DFW',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-26 07:34:00',
                'arrive_dt' => '2026-08-26 12:02:00',
            ],
        ], null, $ownerId);

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
            null,
            null,
            new AirportRepository(null, $this->logger),
        );

        $preview = $builder->build($viewerId, $ownerId, 21);
        self::assertCount(1, $preview);
        self::assertSame('Dallas/Fort Worth, TX then New York, NY', $preview[0]['destination']);
        $types = array_column($preview[0]['itinerary'], 'type');
        self::assertSame(['leg', 'stay', 'leg'], $types);
        self::assertStringContainsString('In Dallas/Fort Worth, TX', $preview[0]['itinerary'][1]['label']);
    }

    public function testTravelPreviewRedactsCityOnItineraryWhenDenied(): void
    {
        $ownerId = $this->insertUser('owner3');
        // Viewer reports to owner → BOTTOM_UP defaults to city+dates.
        $viewerId = $this->insertUser('viewer3', $ownerId);
        $userRepo = new UserRepository($this->db, $this->logger);
        $rules = new VisibilityRuleRepository($this->db);
        $tripRepo = new TripRepository($this->db, $this->logger);

        $rules->upsert(
            $ownerId,
            null,
            VisibilityEngine::DIRECTION_BOTTOM_UP,
            'destination_city',
            false,
            $ownerId,
        );

        $day = (new \DateTimeImmutable('today'))->modify('+5 days');
        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'Chicago, IL',
            startDate: $day->format('Y-m-d'),
            endDate: $day->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'AA',
            flightNumber: '50',
            confirmationCode: 'XYZ',
            origin: 'HSV',
            destination: 'ORD',
            departDt: $day->setTime(9, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(11, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));
        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'AA',
            flightNumber: '60',
            confirmationCode: 'XYZ',
            origin: 'ORD',
            destination: 'DEN',
            departDt: $day->setTime(13, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(15, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $engine = new VisibilityEngine($userRepo, $rules);
        $fields = $engine->getVisibleFields($viewerId, $ownerId)['visible_fields'];
        self::assertNotContains('destination_city', $fields);
        self::assertContains('travel_dates', $fields);

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            $engine,
            new VisibilityBlockRepository($this->db),
        );
        $preview = $builder->build($viewerId, $ownerId, 21);
        self::assertCount(1, $preview);
        self::assertNull($preview[0]['destination']);
        self::assertTrue($preview[0]['redacted']);

        $itin = $preview[0]['itinerary'];
        self::assertCount(3, $itin);
        self::assertSame('layover', $itin[1]['type']);
        self::assertStringStartsWith('Layover', $itin[1]['label']);
        self::assertStringNotContainsString('ORD', $itin[1]['label']);
        self::assertStringNotContainsString('HSV', $itin[0]['label']);
        self::assertStringContainsString('Flight', $itin[0]['label']);
    }

    public function testTravelPreviewHotelUsesPropertyName(): void
    {
        $ownerId = $this->insertUser('hotelguest');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $props = new HotelPropertyRepository($this->db, $this->logger);
        $stays = new HotelStayRepository($this->db, $this->logger, $props);

        $day = (new \DateTimeImmutable('today'))->modify('+6 days');
        $property = $props->create(new \NexWaypoint\Hotels\HotelProperty(
            id: null,
            createdByUserId: $ownerId,
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
            latitude: null,
            longitude: null,
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
        ), $ownerId);

        $stay = $stays->create(new \NexWaypoint\Hotels\HotelStay(
            id: null,
            userId: $ownerId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: $day->format('Y-m-d'),
            stayEnd: $day->modify('+2 days')->format('Y-m-d'),
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: null,
            confirmationCode: null,
            wouldReturn: null,
            notes: null,
            isPrivate: false,
        ), $ownerId);

        $trip = $tripRepo->create(new \NexWaypoint\Trips\Trip(
            id: null,
            ownerId: $ownerId,
            destinationCity: 'New York, NY',
            startDate: $day->format('Y-m-d'),
            endDate: $day->modify('+2 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $tripRepo->addSegment(new \NexWaypoint\Trips\TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '1',
            confirmationCode: 'H1',
            origin: 'HSV',
            destination: 'LGA',
            departDt: $day->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(12, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $tripRepo->replaceTripHotels((int) $trip->id, [(int) $stay->id], $props, $stays, $ownerId);

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
            $stays,
            $props,
        );
        $preview = $builder->build($ownerId, $ownerId, 21);
        self::assertCount(1, $preview);
        $hotelItems = array_values(array_filter(
            $preview[0]['itinerary'],
            static fn (array $i) => $i['type'] === 'hotel'
        ));
        self::assertCount(1, $hotelItems);
        self::assertStringContainsString('Hilton Midtown', $hotelItems[0]['label']);
    }
}
