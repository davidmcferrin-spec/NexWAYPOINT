<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Users\TeamTravelPreviewBuilder;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

final class TripStayPurposeTest extends NexWaypointTestCase
{
    public function testReplaceAndListStayPurposes(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $result = $tripRepo->upsertItineraryByConfirmation($userId, 'PURP1', [
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-24 07:00:00',
                'arrive_dt' => '2026-08-24 09:10:00',
            ],
            [
                'segment_type' => 'flight',
                'origin' => 'DFW',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-26 07:34:00',
                'arrive_dt' => '2026-08-26 12:02:00',
            ],
        ], null, $userId);

        $tripId = (int) $result['trip']->id;
        $tripRepo->replaceStayPurposes($tripId, [
            ['dest_code' => 'DFW', 'purpose' => 'theSWITCH'],
            ['dest_code' => 'LGA', 'purpose' => 'Edit bay'],
        ], $userId);

        $listed = $tripRepo->stayPurposesForTrip($tripId);
        self::assertCount(2, $listed);
        self::assertSame('DFW', $listed[0]['dest_code']);
        self::assertSame('theSWITCH', $listed[0]['purpose']);
        self::assertSame('LGA', $listed[1]['dest_code']);
        self::assertSame('Edit bay', $listed[1]['purpose']);
    }

    public function testPreviewAttachesPurposePerStay(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $result = $tripRepo->upsertItineraryByConfirmation($userId, 'PURP2', [
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-24 07:00:00',
                'arrive_dt' => '2026-08-24 09:10:00',
            ],
            [
                'segment_type' => 'flight',
                'origin' => 'DFW',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-26 07:34:00',
                'arrive_dt' => '2026-08-26 12:02:00',
            ],
        ], 'Dallas/Fort Worth, TX', $userId);

        $trip = $result['trip'];
        $tripRepo->update($trip->id !== null ? new \NexWaypoint\Trips\Trip(
            id: $trip->id,
            ownerId: $trip->ownerId,
            destinationCity: $trip->destinationCity,
            startDate: $trip->startDate,
            endDate: $trip->endDate,
            status: $trip->status,
            tripPurpose: 'theSWITCH',
            notes: $trip->notes,
            isPrivate: $trip->isPrivate,
        ) : $trip, $userId);

        $tripRepo->replaceStayPurposes((int) $trip->id, [
            ['dest_code' => 'DFW', 'purpose' => 'theSWITCH'],
            ['dest_code' => 'LGA', 'purpose' => 'Edit bay'],
        ], $userId);

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
            null,
            null,
            new AirportRepository($this->db, $this->logger),
        );
        $preview = $builder->build($userId, $userId, 90);
        self::assertNotSame([], $preview);
        $stays = $preview[0]['stays'];
        self::assertCount(2, $stays);
        self::assertSame('theSWITCH', $stays[0]['purpose']);
        self::assertSame('Edit bay', $stays[1]['purpose']);
    }

    public function testLegacyTripPurposeFallsBackToFirstStayOnly(): void
    {
        $userId = $this->insertUser('dave');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $result = $tripRepo->upsertItineraryByConfirmation($userId, 'PURP3', [
            [
                'segment_type' => 'flight',
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-24 07:00:00',
                'arrive_dt' => '2026-08-24 09:10:00',
            ],
            [
                'segment_type' => 'flight',
                'origin' => 'DFW',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-26 07:34:00',
                'arrive_dt' => '2026-08-26 12:02:00',
            ],
        ], 'Dallas/Fort Worth, TX', $userId);

        $trip = $result['trip'];
        $tripRepo->update(new \NexWaypoint\Trips\Trip(
            id: $trip->id,
            ownerId: $trip->ownerId,
            destinationCity: $trip->destinationCity,
            startDate: $trip->startDate,
            endDate: $trip->endDate,
            status: $trip->status,
            tripPurpose: 'theSWITCH',
            notes: $trip->notes,
            isPrivate: $trip->isPrivate,
        ), $userId);

        $builder = new TeamTravelPreviewBuilder(
            $tripRepo,
            new VisibilityEngine(new UserRepository($this->db, $this->logger), new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
            null,
            null,
            new AirportRepository($this->db, $this->logger),
        );
        $stays = $builder->build($userId, $userId, 90)[0]['stays'];
        self::assertSame('theSWITCH', $stays[0]['purpose']);
        self::assertNull($stays[1]['purpose']);
    }
}
