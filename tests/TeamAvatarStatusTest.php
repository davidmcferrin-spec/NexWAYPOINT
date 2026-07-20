<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
use NexWaypoint\Users\TeamLocationResolver;
use NexWaypoint\Users\UserRepository;

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
}
