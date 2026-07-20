<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
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

        $engine = new TripStatusEngine($tripRepo, $this->logger);
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

    public function testUpcomingDestinationWinsOverHomeWhenAtBase(): void
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
        self::assertSame(41.8781, $result['location']['lat']);
        self::assertStringContainsString('Chicago', (string) $result['upcoming']);
        self::assertStringContainsString('·', (string) $result['upcoming']);
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

        // en_route uses destination from status, not upcoming override label.
        self::assertNull($result['upcoming']);
        self::assertTrue(TeamLocationResolver::isAtBaseStatus('home'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('en_route'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('pre_flight'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('post_flight'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('layover'));
        self::assertFalse(TeamLocationResolver::isAtBaseStatus('remote', ['from_itinerary' => true]));
        self::assertTrue(TeamLocationResolver::isAtBaseStatus('remote'));
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
}
