<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripAutoCompleter;
use NexWaypoint\Trips\TripRepository;

final class TripAutoCompleterTest extends NexWaypointTestCase
{
    private function completer(TripRepository $tripRepo): TripAutoCompleter
    {
        return new TripAutoCompleter(
            $tripRepo,
            $this->logger,
            new AirportRepository($this->db, $this->logger),
        );
    }

    /**
     * @param list<array<string, mixed>> $legs
     */
    private function roundTrip(TripRepository $tripRepo, int $userId, string $code, array $legs): Trip
    {
        $result = $tripRepo->upsertItineraryByConfirmation($userId, $code, $legs, null, $userId);
        return $result['trip'];
    }

    public function testCompletesRoundTripAfterTwoHoursHome(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $trip = $this->roundTrip($tripRepo, $userId, 'HOME2H', [
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-08-20 08:00:00',
                'arrive_dt' => '2026-08-20 10:00:00',
            ],
            [
                'segment_type' => 'flight',
                'origin' => 'DEN',
                'destination' => 'HSV',
                'depart_dt' => '2026-08-22 16:00:00',
                'arrive_dt' => '2026-08-22 19:00:00',
            ],
        ]);

        $completer = $this->completer($tripRepo);
        $tz = new \DateTimeZone('America/Chicago');

        $stillOpen = $completer->completeForOwner($userId, new \DateTimeImmutable('2026-08-22 21:44:00', $tz));
        self::assertSame(0, $stillOpen);
        self::assertSame('planned', $tripRepo->find((int) $trip->id)?->status);

        $done = $completer->completeForOwner($userId, new \DateTimeImmutable('2026-08-22 21:45:00', $tz));
        self::assertSame(1, $done);
        self::assertSame('completed', $tripRepo->find((int) $trip->id)?->status);
    }

    public function testDoesNotCompleteOpenEndedLastCity(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $trip = $this->roundTrip($tripRepo, $userId, 'ONEWAY', [
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-20 08:00:00',
                'arrive_dt' => '2026-08-20 11:00:00',
            ],
        ]);

        $done = $this->completer($tripRepo)->completeForOwner(
            $userId,
            new \DateTimeImmutable('2026-08-20 16:00:00', new \DateTimeZone('America/New_York')),
        );
        self::assertSame(0, $done);
        self::assertSame('planned', $tripRepo->find((int) $trip->id)?->status);
    }

    public function testDoesNotCompleteWhenLaterOutboundRemains(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $trip = $this->roundTrip($tripRepo, $userId, 'MULTI', [
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-08-20 08:00:00',
                'arrive_dt' => '2026-08-20 10:00:00',
            ],
            [
                'segment_type' => 'flight',
                'origin' => 'DEN',
                'destination' => 'HSV',
                'depart_dt' => '2026-08-22 16:00:00',
                'arrive_dt' => '2026-08-22 19:00:00',
            ],
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'LAX',
                'depart_dt' => '2026-08-25 07:00:00',
                'arrive_dt' => '2026-08-25 10:00:00',
            ],
        ]);

        $done = $this->completer($tripRepo)->completeForOwner(
            $userId,
            new \DateTimeImmutable('2026-08-22 22:00:00', new \DateTimeZone('America/Chicago')),
        );
        self::assertSame(0, $done);
        self::assertSame('planned', $tripRepo->find((int) $trip->id)?->status);
    }

    public function testDoesNotCompleteWhileHotelStillCovers(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $props = new HotelPropertyRepository($this->db, $this->logger);
        $stays = new HotelStayRepository($this->db, $this->logger, $props);

        $trip = $this->roundTrip($tripRepo, $userId, 'HOTELHOME', [
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-08-20 08:00:00',
                'arrive_dt' => '2026-08-20 10:00:00',
            ],
            [
                'segment_type' => 'flight',
                'origin' => 'DEN',
                'destination' => 'HSV',
                'depart_dt' => '2026-08-22 16:00:00',
                'arrive_dt' => '2026-08-22 19:00:00',
            ],
        ]);

        $property = $props->findOrCreate('Home Hilton', 'Huntsville', 'AL', $userId);
        $stay = $stays->create(new HotelStay(
            id: null,
            userId: $userId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: '2026-08-22',
            stayEnd: '2026-08-23',
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: null,
            confirmationCode: 'HH1',
            wouldReturn: null,
            notes: null,
            isPrivate: false,
        ), $userId);
        $tripRepo->replaceTripHotels((int) $trip->id, [(int) $stay->id], $props, $stays, $userId);

        $done = $this->completer($tripRepo)->completeForOwner(
            $userId,
            new \DateTimeImmutable('2026-08-22 22:00:00', new \DateTimeZone('America/Chicago')),
        );
        self::assertSame(0, $done);
        self::assertSame('planned', $tripRepo->find((int) $trip->id)?->status);
    }

    public function testHotelOnlyCompletesTwoHoursAfterCheckout(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $props = new HotelPropertyRepository($this->db, $this->logger);
        $stays = new HotelStayRepository($this->db, $this->logger, $props);

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver',
            startDate: '2026-08-20',
            endDate: '2026-08-22',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ), $userId);

        $property = $props->findOrCreate('Denver Hilton', 'Denver', 'CO', $userId);
        $stay = $stays->create(new HotelStay(
            id: null,
            userId: $userId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: '2026-08-20',
            stayEnd: '2026-08-22',
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: null,
            confirmationCode: 'DENH',
            wouldReturn: null,
            notes: null,
            isPrivate: false,
        ), $userId);
        $tripRepo->replaceTripHotels((int) $trip->id, [(int) $stay->id], $props, $stays, $userId);

        $completer = $this->completer($tripRepo);
        self::assertSame(0, $completer->completeForOwner($userId, new \DateTimeImmutable('2026-08-22 12:00:00')));
        self::assertSame('planned', $tripRepo->find((int) $trip->id)?->status);

        self::assertSame(1, $completer->completeDue(new \DateTimeImmutable('2026-08-22 13:00:00')));
        self::assertSame('completed', $tripRepo->find((int) $trip->id)?->status);
    }
}
