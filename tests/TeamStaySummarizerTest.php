<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Users\TeamStaySummarizer;
use PHPUnit\Framework\TestCase;

final class TeamStaySummarizerTest extends TestCase
{
    public function testMultiCityOneWayHasOpenEndedLastStay(): void
    {
        $sum = new TeamStaySummarizer(new AirportRepository(null));
        $stays = $sum->staysForTrip(
            $this->trip('Dallas/Fort Worth, TX', '2026-08-24', '2026-08-26'),
            [
                $this->flight('HSV', 'DFW', '2026-08-24 07:00:00', '2026-08-24 09:10:00'),
                $this->flight('DFW', 'LGA', '2026-08-26 07:34:00', '2026-08-26 12:02:00'),
            ],
        );

        self::assertCount(2, $stays);
        self::assertSame('Dallas/Fort Worth, TX', $stays[0]['city']);
        self::assertSame('2026-08-24', $stays[0]['start']);
        self::assertSame('2026-08-26', $stays[0]['end']);
        self::assertSame('Mon Aug 24 – Wed Aug 26', $stays[0]['dates']);
        self::assertFalse($stays[0]['open_ended']);

        self::assertSame('New York, NY', $stays[1]['city']);
        self::assertSame('2026-08-26', $stays[1]['start']);
        self::assertNull($stays[1]['end']);
        self::assertSame('Wed Aug 26 – open-ended', $stays[1]['dates']);
        self::assertTrue($stays[1]['open_ended']);
    }

    public function testRoundTripLastStayEndsOnReturnDepart(): void
    {
        $sum = new TeamStaySummarizer(new AirportRepository(null));
        $stays = $sum->staysForTrip(
            $this->trip('Denver, CO', '2026-09-01', '2026-09-04'),
            [
                $this->flight('HSV', 'DEN', '2026-09-01 08:00:00', '2026-09-01 10:00:00'),
                $this->flight('DEN', 'HSV', '2026-09-04 17:00:00', '2026-09-04 20:00:00'),
            ],
        );

        self::assertCount(1, $stays);
        self::assertSame('Denver, CO', $stays[0]['city']);
        self::assertFalse($stays[0]['open_ended']);
        self::assertSame('2026-09-01', $stays[0]['start']);
        self::assertSame('2026-09-04', $stays[0]['end']);
        self::assertSame('Tue Sep 1 – Fri Sep 4', $stays[0]['dates']);
    }

    public function testWeekCitiesJoinsOverlappingStays(): void
    {
        $sum = new TeamStaySummarizer();
        $stays = [
            [
                'city' => 'Dallas/Fort Worth, TX',
                'start' => '2026-08-24',
                'end' => '2026-08-26',
                'open_ended' => false,
            ],
            [
                'city' => 'New York, NY',
                'start' => '2026-08-26',
                'end' => null,
                'open_ended' => true,
            ],
        ];

        $before = $sum->weekCities($stays, new \DateTimeImmutable('2026-08-16'));
        self::assertNull($before);

        $during = $sum->weekCities($stays, new \DateTimeImmutable('2026-08-24'));
        self::assertSame('Dallas/Fort Worth, TX → New York, NY', $during);
    }

    public function testSundayOfWeekAndCalendarWindows(): void
    {
        $sum = new TeamStaySummarizer();
        $wed = new \DateTimeImmutable('2026-08-19');
        $sunday = TeamStaySummarizer::sundayOfWeek($wed);
        self::assertSame('2026-08-16', $sunday->format('Y-m-d'));
        self::assertSame('2026-08-16', TeamStaySummarizer::sundayOfWeek($sunday)->format('Y-m-d'));

        $stays = [
            [
                'city' => 'Dallas/Fort Worth, TX',
                'start' => '2026-08-24',
                'end' => '2026-08-26',
                'open_ended' => false,
            ],
            [
                'city' => 'New York, NY',
                'start' => '2026-08-26',
                'end' => null,
                'open_ended' => true,
            ],
        ];
        self::assertNull($sum->weekCities($stays, $sunday, 7));
        self::assertSame(
            'Dallas/Fort Worth, TX → New York, NY',
            $sum->weekCities($stays, $sunday->modify('+7 days'), 7),
        );
    }

    public function testGanttCellsPaintTravelThenHome(): void
    {
        $sum = new TeamStaySummarizer();
        $stays = [
            [
                'city' => 'Dallas/Fort Worth, TX',
                'start' => '2026-08-24',
                'end' => '2026-08-26',
                'open_ended' => false,
            ],
            [
                'city' => 'New York, NY',
                'start' => '2026-08-26',
                'end' => null,
                'open_ended' => true,
            ],
        ];

        $cells = $sum->ganttCells($stays, new \DateTimeImmutable('2026-08-23'), 6);
        self::assertSame([
            null,
            'Dallas/Fort Worth, TX',
            'Dallas/Fort Worth, TX',
            'New York, NY',
            'New York, NY',
            'New York, NY',
        ], $cells);
    }

    public function testTravelDayPrefersLaterStayStart(): void
    {
        $sum = new TeamStaySummarizer();
        $city = $sum->cityOnDate(
            [
                [
                    'city' => 'Dallas/Fort Worth, TX',
                    'start' => '2026-08-24',
                    'end' => '2026-08-26',
                    'open_ended' => false,
                ],
                [
                    'city' => 'New York, NY',
                    'start' => '2026-08-26',
                    'end' => null,
                    'open_ended' => true,
                ],
            ],
            new \DateTimeImmutable('2026-08-26'),
        );
        self::assertSame('New York, NY', $city);
    }

    private function trip(string $city, string $start, string $end): Trip
    {
        return new Trip(
            id: 1,
            ownerId: 1,
            destinationCity: $city,
            startDate: $start,
            endDate: $end,
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        );
    }

    private function flight(string $origin, string $dest, string $depart, string $arrive): TripSegment
    {
        return new TripSegment(
            id: null,
            tripId: 1,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'AA',
            flightNumber: '1',
            confirmationCode: null,
            origin: $origin,
            destination: $dest,
            departDt: $depart,
            arriveDt: $arrive,
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        );
    }
}
