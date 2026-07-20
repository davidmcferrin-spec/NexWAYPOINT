<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
use NexWaypoint\Users\TeamLocationResolver;
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
}
