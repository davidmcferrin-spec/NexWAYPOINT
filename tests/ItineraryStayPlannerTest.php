<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\ItineraryStayPlanner;
use PHPUnit\Framework\TestCase;

final class ItineraryStayPlannerTest extends TestCase
{
    public function testMultiCityOneWayUsesBothOvernightCities(): void
    {
        $stays = (new ItineraryStayPlanner())->staysFromLegArrays([
            [
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-24 07:00:00',
                'arrive_dt' => '2026-08-24 09:10:00',
            ],
            [
                'origin' => 'DFW',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-26 07:34:00',
                'arrive_dt' => '2026-08-26 12:02:00',
            ],
        ]);

        self::assertSame(['DFW', 'LGA'], array_column($stays, 'destination'));
        self::assertSame('DFW', (new ItineraryStayPlanner())->firstStayDestination([
            [
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-24 07:00:00',
                'arrive_dt' => '2026-08-24 09:10:00',
            ],
            [
                'origin' => 'DFW',
                'destination' => 'LGA',
                'depart_dt' => '2026-08-26 07:34:00',
                'arrive_dt' => '2026-08-26 12:02:00',
            ],
        ]));
    }

    public function testSameDayConnectionIsNotAStay(): void
    {
        $stays = (new ItineraryStayPlanner())->staysFromLegArrays([
            [
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-08-10 06:00:00',
                'arrive_dt' => '2026-08-10 08:15:00',
            ],
            [
                'origin' => 'DFW',
                'destination' => 'LAX',
                'depart_dt' => '2026-08-10 09:30:00',
                'arrive_dt' => '2026-08-10 11:00:00',
            ],
        ]);

        self::assertSame(['LAX'], array_column($stays, 'destination'));
    }

    public function testRoundTripLastArrivalHomeIsNotAStay(): void
    {
        $stays = (new ItineraryStayPlanner())->staysFromLegArrays([
            [
                'origin' => 'HSV',
                'destination' => 'DEN',
                'depart_dt' => '2026-09-01 08:00:00',
                'arrive_dt' => '2026-09-01 10:00:00',
            ],
            [
                'origin' => 'DEN',
                'destination' => 'HSV',
                'depart_dt' => '2026-09-04 17:00:00',
                'arrive_dt' => '2026-09-04 20:00:00',
            ],
        ]);

        self::assertSame(['DEN'], array_column($stays, 'destination'));
    }
}
