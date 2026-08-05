<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\FlightAwareClient;
use NexWaypoint\Trips\FlightStatusRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

final class FlightAwareClientTest extends NexWaypointTestCase
{
    public function testSelectBestFlightPrefersTravelDateAndRoute(): void
    {
        $client = $this->clientWithoutApiKey();
        $segment = $this->segmentStub(
            origin: 'HSV',
            destination: 'DEN',
            departDt: '2026-08-14 08:00:00',
        );
        $expected = (new AirportRepository(null, $this->logger))
            ->instant('HSV', '2026-08-14 08:00:00')
            ->setTimezone(new \DateTimeZone('UTC'));

        $flights = [
            // Same number, previous day, wrong day.
            [
                'fa_flight_id' => 'UAL1234-prev',
                'status' => 'Landed',
                'scheduled_out' => '2026-08-13T13:00:00Z',
                'origin' => ['code_iata' => 'HSV'],
                'destination' => ['code_iata' => 'DEN'],
            ],
            // Travel day, correct route.
            [
                'fa_flight_id' => 'UAL1234-target',
                'status' => 'Scheduled',
                'scheduled_out' => '2026-08-14T13:00:00Z', // 08:00 CDT ≈ 13:00Z in summer
                'origin' => ['code_iata' => 'HSV'],
                'destination' => ['code_iata' => 'DEN'],
            ],
            // Travel day-ish but different route (codeshare / different city pair).
            [
                'fa_flight_id' => 'UAL1234-other',
                'status' => 'Scheduled',
                'scheduled_out' => '2026-08-14T13:05:00Z',
                'origin' => ['code_iata' => 'ORD'],
                'destination' => ['code_iata' => 'DEN'],
            ],
        ];

        $best = $client->selectBestFlight($flights, $segment, $expected);
        self::assertNotNull($best);
        self::assertSame('UAL1234-target', $best['fa_flight_id']);
    }

    public function testSelectBestFlightRejectsFarOffInstances(): void
    {
        $client = $this->clientWithoutApiKey();
        $segment = $this->segmentStub('HSV', 'DEN', '2026-08-14 08:00:00');
        $expected = new \DateTimeImmutable('2026-08-14 13:00:00', new \DateTimeZone('UTC'));

        $best = $client->selectBestFlight([
            [
                'fa_flight_id' => 'far',
                'scheduled_out' => '2026-08-20T13:00:00Z',
                'origin' => ['code_iata' => 'HSV'],
                'destination' => ['code_iata' => 'DEN'],
            ],
        ], $segment, $expected);

        self::assertNull($best);
    }

    public function testLookupWindowUsesOriginTimezone(): void
    {
        $client = $this->clientWithoutApiKey();
        $segment = $this->segmentStub('HSV', 'DEN', '2026-08-14 08:00:00');
        $now = new \DateTimeImmutable('2026-08-14 12:00:00', new \DateTimeZone('UTC'));

        $window = $client->lookupWindowForSegment($segment, $now);
        self::assertNotNull($window);

        // HSV is America/Chicago; 08:00 CDT = 13:00 UTC in August.
        self::assertSame('2026-08-14T13:00:00+00:00', $window['expected']->format('c'));
        self::assertSame('2026-08-14T07:00:00+00:00', $window['start']->format('c'));
        self::assertSame('2026-08-15T07:00:00+00:00', $window['end']->format('c'));
    }

    public function testFindSegmentsNeedingEnrichmentUsesDepartWindow(): void
    {
        $userId = $this->insertUser('fa_sweep');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $now = new \DateTimeImmutable('now');

        $inWindow = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver, CO',
            startDate: $now->format('Y-m-d'),
            endDate: $now->modify('+1 day')->format('Y-m-d'),
            status: 'active',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $inWindow->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '1',
            confirmationCode: 'IN1',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $now->modify('+3 hours')->format('Y-m-d H:i:s'),
            arriveDt: $now->modify('+6 hours')->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $tooFar = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: $now->modify('+10 days')->format('Y-m-d'),
            endDate: $now->modify('+12 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $tooFar->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '2',
            confirmationCode: 'FAR1',
            origin: 'HSV',
            destination: 'ORD',
            departDt: $now->modify('+10 days')->format('Y-m-d H:i:s'),
            arriveDt: $now->modify('+10 days')->modify('+2 hours')->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $tooOld = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Atlanta, GA',
            startDate: $now->modify('-5 days')->format('Y-m-d'),
            endDate: $now->modify('-4 days')->format('Y-m-d'),
            status: 'active',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $tooOld->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '3',
            confirmationCode: 'OLD1',
            origin: 'HSV',
            destination: 'ATL',
            departDt: $now->modify('-3 days')->format('Y-m-d H:i:s'),
            arriveDt: $now->modify('-3 days')->modify('+2 hours')->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $candidates = $tripRepo->findSegmentsNeedingEnrichment(48, 18);
        $numbers = array_map(static fn (TripSegment $s): string => (string) $s->flightNumber, $candidates);

        self::assertContains('1', $numbers);
        self::assertNotContains('2', $numbers);
        self::assertNotContains('3', $numbers);
    }

    private function clientWithoutApiKey(): FlightAwareClient
    {
        // Api key is not needed for pure selection/window helpers.
        putenv('FLIGHTAWARE_API_KEY=test-key-not-used');
        $_ENV['FLIGHTAWARE_API_KEY'] = 'test-key-not-used';

        return new FlightAwareClient(
            $this->logger,
            new FlightStatusRepository($this->db),
            new AirportRepository(null, $this->logger),
            'test-key-not-used',
        );
    }

    private function segmentStub(string $origin, string $destination, string $departDt): TripSegment
    {
        return new TripSegment(
            id: 1,
            tripId: 1,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '1234',
            confirmationCode: 'TEST',
            origin: $origin,
            destination: $destination,
            departDt: $departDt,
            arriveDt: null,
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        );
    }
}
