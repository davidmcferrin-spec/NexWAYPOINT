<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Users\User;

/**
 * Builds ICS events for the feed owner's own travel:
 * timed transit legs + "In {city}" presence between legs (until next depart or trip end).
 *
 * Window: [asOf - daysBack, asOf + daysAhead]. Any overlapping trip is emitted
 * in full (outbound before the window, return inside, etc.).
 */
final class PersonalTravelFeedBuilder
{
    public const DEFAULT_DAYS_BACK = 14;
    public const DEFAULT_DAYS_AHEAD = 90;

    private readonly TravelPresencePlanner $presence;

    public function __construct(
        private readonly TripRepository $trips,
        private readonly ?AirportRepository $airports = null,
        private readonly int $daysBack = self::DEFAULT_DAYS_BACK,
        private readonly int $daysAhead = self::DEFAULT_DAYS_AHEAD,
    ) {
        $this->presence = new TravelPresencePlanner($airports);
    }

    /**
     * @return list<IcsEvent>
     */
    public function buildEvents(int|User $owner, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('today');
        $ownerUserId = $owner instanceof User ? $owner->id : $owner;
        $homeCity = $owner instanceof User ? $owner->homeCity : null;
        $homeState = $owner instanceof User ? $owner->homeState : null;

        $events = [];

        foreach ($this->trips->findInDateWindow($ownerUserId, $this->daysBack, $this->daysAhead, $asOf) as $trip) {
            if ($trip->id === null || $trip->status === 'cancelled') {
                continue;
            }

            $segments = $this->trips->segmentsForTrip($trip->id);
            $transit = $this->presence->transitLegs($segments);
            $windows = $this->presence->presenceWindows($segments, $trip, $homeCity, $homeState);

            // Fat trip block only when there is no itinerary to hang presence on.
            if ($transit === []) {
                $events[] = $this->tripAllDay($trip);
            }

            foreach ($transit as $segment) {
                $timed = $this->transitEvent($segment, $trip);
                if ($timed !== null) {
                    $events[] = $timed;
                }
            }

            foreach ($windows as $window) {
                $events[] = $this->presenceEvent($window, $trip);
            }
        }

        return $events;
    }

    private function tripAllDay(Trip $trip): IcsEvent
    {
        $endExclusive = $this->exclusiveEndDate($trip->endDate);
        $summary = 'Trip · ' . trim($trip->destinationCity);
        $descParts = [];
        if ($trip->tripPurpose !== null && trim($trip->tripPurpose) !== '') {
            $descParts[] = trim($trip->tripPurpose);
        }
        if ($trip->notes !== null && trim($trip->notes) !== '') {
            $descParts[] = trim($trip->notes);
        }

        return new IcsEvent(
            uid: 'nxwp-trip-' . (int) $trip->id . '@nexwaypoint',
            summary: $summary,
            description: $descParts !== [] ? implode("\n", $descParts) : null,
            location: trim($trip->destinationCity),
            dtStart: $trip->startDate,
            dtEnd: $endExclusive,
            allDay: true,
            categories: ['NexWAYPOINT', 'Trip'],
        );
    }

    /**
     * @param array{
     *   after_segment_id: int,
     *   city: string,
     *   start: \DateTimeImmutable,
     *   end: \DateTimeImmutable
     * } $window
     */
    private function presenceEvent(array $window, Trip $trip): IcsEvent
    {
        $city = $window['city'];
        return new IcsEvent(
            uid: 'nxwp-presence-' . $window['after_segment_id'] . '@nexwaypoint',
            summary: 'In ' . $city,
            description: 'Trip · ' . trim($trip->destinationCity),
            location: $city,
            dtStart: $window['start']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            dtEnd: $window['end']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            allDay: false,
            categories: ['NexWAYPOINT', 'Presence'],
        );
    }

    private function transitEvent(TripSegment $segment, Trip $trip): ?IcsEvent
    {
        if ($segment->id === null || $segment->departDt === null || $segment->arriveDt === null) {
            return null;
        }

        $start = $this->presence->instant($segment->origin, $segment->departDt);
        $end = $this->presence->instant($segment->destination, $segment->arriveDt);
        if ($end <= $start) {
            $end = $start->modify('+1 hour');
        }

        $kind = match ($segment->segmentType) {
            'train' => 'Train',
            'car' => 'Ground',
            default => 'Flight',
        };

        $bits = [];
        if ($segment->carrier !== null && trim($segment->carrier) !== '') {
            $bits[] = trim($segment->carrier);
        }
        if ($segment->flightNumber !== null && trim($segment->flightNumber) !== '') {
            $bits[] = trim($segment->flightNumber);
        }
        $route = $this->presence->routeLabel($segment->origin, $segment->destination);
        $summary = trim(implode(' ', $bits));
        if ($route !== '') {
            $summary = $summary !== '' ? $summary . ' · ' . $route : $route;
        }
        if ($summary === '') {
            $summary = $kind;
        }

        $desc = [];
        $desc[] = $kind . ' · ' . trim($trip->destinationCity);
        if ($segment->confirmationCode !== null && trim($segment->confirmationCode) !== '') {
            $desc[] = 'Confirmation: ' . trim($segment->confirmationCode);
        }

        $status = $segment->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED';

        return new IcsEvent(
            uid: 'nxwp-seg-' . $segment->id . '@nexwaypoint',
            summary: $summary,
            description: implode("\n", $desc),
            location: $route !== '' ? $route : null,
            dtStart: $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            dtEnd: $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            allDay: false,
            categories: ['NexWAYPOINT', $kind],
            status: $status,
        );
    }

    private function exclusiveEndDate(string $endDate): string
    {
        try {
            return (new \DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d');
        } catch (\Exception) {
            return $endDate;
        }
    }
}
