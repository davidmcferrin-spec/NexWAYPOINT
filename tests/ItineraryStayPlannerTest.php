<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\AirportRepository;
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

    public function testCrossTimezoneConnectionIsNotAStayWhenAirportsProvided(): void
    {
        // Arrive LAX 21:30 PT; next depart JFK 01:00 ET. Dest TZ ≠ next-origin TZ.
        $legs = [
            [
                'origin' => 'HSV',
                'destination' => 'LAX',
                'depart_dt' => '2026-08-10 16:00:00',
                'arrive_dt' => '2026-08-10 21:30:00',
            ],
            [
                'origin' => 'JFK',
                'destination' => 'BOS',
                'depart_dt' => '2026-08-11 01:00:00',
                'arrive_dt' => '2026-08-11 02:15:00',
            ],
        ];

        // Naive wall-clock (both APP_TIMEZONE): 21:30 → 01:00 = 3.5h → stay at LAX.
        $naive = (new ItineraryStayPlanner())->staysFromLegArrays($legs);
        self::assertSame(['LAX', 'BOS'], array_column($naive, 'destination'));

        // 21:30 PT = 00:30 ET; gap to 01:00 ET is 30m → not a stay at LAX.
        $airports = new AirportRepository(null);
        $aware = (new ItineraryStayPlanner($airports))->staysFromLegArrays($legs);
        self::assertSame(['BOS'], array_column($aware, 'destination'));
        self::assertSame('BOS', (new ItineraryStayPlanner($airports))->firstStayDestination($legs));
    }
}
