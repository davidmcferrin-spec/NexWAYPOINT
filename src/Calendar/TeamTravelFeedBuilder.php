<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Users\User;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;

/**
 * Visibility-filtered team ICS: presence ("Name - Denver") between legs,
 * plus timed transit only when flight/carrier fields are visible.
 *
 * Window: [asOf - daysBack, asOf + daysAhead]. Any overlapping trip is emitted
 * in full (same contract as PersonalTravelFeedBuilder).
 */
final class TeamTravelFeedBuilder
{
    public const DEFAULT_DAYS_BACK = 14;
    public const DEFAULT_DAYS_AHEAD = 90;

    private readonly TravelPresencePlanner $presence;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TripRepository $trips,
        private readonly VisibilityEngine $visibility,
        private readonly VisibilityBlockRepository $blocks,
        private readonly ?AirportRepository $airports = null,
        private readonly int $daysBack = self::DEFAULT_DAYS_BACK,
        private readonly int $daysAhead = self::DEFAULT_DAYS_AHEAD,
    ) {
        $this->presence = new TravelPresencePlanner($airports);
    }

    /**
     * @return list<IcsEvent>
     */
    public function buildEvents(CalendarFeed $feed, ?\DateTimeImmutable $asOf = null): array
    {
        if ($feed->kind !== CalendarFeed::KIND_TEAM) {
            return [];
        }

        $asOf ??= new \DateTimeImmutable('today');
        $viewerId = $feed->ownerUserId;
        $events = [];

        foreach ($this->subjectsForFeed($feed) as $subject) {
            foreach ($this->trips->findInDateWindow($subject->id, $this->daysBack, $this->daysAhead, $asOf) as $trip) {
                if ($trip->id === null) {
                    continue;
                }
                if ($trip->isPrivate) {
                    continue;
                }
                if ($this->blocks->isHiddenFromViewer(
                    $subject->id,
                    $viewerId,
                    $trip->isPrivate,
                    VisibilityBlockRepository::TYPE_TRIP,
                    $trip->id,
                )) {
                    continue;
                }

                $fields = $this->visibility->getVisibleFields(
                    $viewerId,
                    $subject->id,
                    $trip->isPrivate,
                )['visible_fields'];

                $canCity = in_array('destination_city', $fields, true);
                $canDates = in_array('travel_dates', $fields, true);
                $canPurpose = in_array('trip_purpose', $fields, true);
                $canFlight = in_array('flight_number', $fields, true);
                $canCarrier = in_array('carrier', $fields, true);

                if (!$canDates && !$canCity) {
                    continue;
                }

                $segments = $this->trips->segmentsForTrip($trip->id);
                $transit = $this->presence->transitLegs($segments);
                $windows = $canCity
                    ? $this->presence->presenceWindows(
                        $segments,
                        $trip,
                        $subject->homeCity,
                        $subject->homeState,
                    )
                    : [];

                $emittedPresence = false;
                if ($canCity && $canDates) {
                    foreach ($windows as $window) {
                        $events[] = $this->presenceEvent($subject, $trip, $window);
                        $emittedPresence = true;
                    }
                }

                // Fallback all-day when no presence windows (hotel-only, re-base home, etc.).
                if ($canDates && !$emittedPresence) {
                    $allDay = $this->tripAllDay($subject, $trip, $canCity, $canPurpose);
                    if ($allDay !== null) {
                        $events[] = $allDay;
                    }
                }

                if ($canFlight || $canCarrier) {
                    foreach ($transit as $segment) {
                        $timed = $this->transitEvent(
                            $subject,
                            $trip,
                            $segment,
                            $canCity,
                            $canFlight,
                            $canCarrier,
                        );
                        if ($timed !== null) {
                            $events[] = $timed;
                        }
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @return list<User>
     */
    private function subjectsForFeed(CalendarFeed $feed): array
    {
        $ownerId = $feed->ownerUserId;
        if ($feed->memberUserIds === null) {
            $out = [];
            foreach ($this->users->findAllActive(false) as $user) {
                if ($user->id !== $ownerId) {
                    $out[] = $user;
                }
            }
            return $out;
        }

        $out = [];
        foreach ($feed->memberUserIds as $id) {
            if ($id === $ownerId) {
                continue;
            }
            $user = $this->users->find($id);
            if ($user !== null && $user->isActive && !$user->isSystem) {
                $out[] = $user;
            }
        }
        return $out;
    }

    private function tripAllDay(User $subject, Trip $trip, bool $canCity, bool $canPurpose): ?IcsEvent
    {
        $city = $canCity ? trim($trip->destinationCity) : null;
        if ($city === '') {
            $city = null;
        }
        $name = $subject->displayName;
        $summary = $city !== null ? $name . ' - ' . $city : $name . ' - Traveling';

        $desc = [];
        if ($canPurpose && $trip->tripPurpose !== null && trim($trip->tripPurpose) !== '') {
            $desc[] = trim($trip->tripPurpose);
        }

        return new IcsEvent(
            uid: 'nxwp-team-trip-' . $subject->id . '-' . (int) $trip->id . '@nexwaypoint',
            summary: $summary,
            description: $desc !== [] ? implode("\n", $desc) : null,
            location: $city,
            dtStart: $trip->startDate,
            dtEnd: $this->exclusiveEndDate($trip->endDate),
            allDay: true,
            categories: ['NexWAYPOINT', 'Team'],
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
    private function presenceEvent(User $subject, Trip $trip, array $window): IcsEvent
    {
        $city = $window['city'];
        return new IcsEvent(
            uid: 'nxwp-team-presence-' . $subject->id . '-' . $window['after_segment_id'] . '@nexwaypoint',
            summary: $subject->displayName . ' - ' . $city,
            description: null,
            location: $city,
            dtStart: $window['start']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            dtEnd: $window['end']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            allDay: false,
            categories: ['NexWAYPOINT', 'Team', 'Presence'],
        );
    }

    private function transitEvent(
        User $subject,
        Trip $trip,
        TripSegment $segment,
        bool $canCity,
        bool $canFlight,
        bool $canCarrier,
    ): ?IcsEvent {
        if ($segment->status === 'cancelled' || $segment->id === null) {
            return null;
        }
        if ($segment->departDt === null || $segment->arriveDt === null) {
            return null;
        }
        if (!$canFlight && !$canCarrier && !$canCity) {
            return null;
        }

        $start = $this->presence->instant($segment->origin, $segment->departDt);
        $end = $this->presence->instant($segment->destination, $segment->arriveDt);
        if ($end <= $start) {
            $end = $start->modify('+1 hour');
        }

        $bits = [$subject->displayName];
        if ($canCarrier && $segment->carrier !== null && trim($segment->carrier) !== '') {
            $bits[] = trim($segment->carrier);
        }
        if ($canFlight && $segment->flightNumber !== null && trim($segment->flightNumber) !== '') {
            $bits[] = trim($segment->flightNumber);
        }
        $route = '';
        if ($canCity) {
            $route = $this->presence->routeLabel($segment->origin, $segment->destination);
            if ($route !== '') {
                $bits[] = $route;
            }
        }

        $summary = implode(' - ', array_filter($bits, static fn (string $b): bool => $b !== ''));
        if ($summary === $subject->displayName) {
            $summary .= ' - Flight';
        }

        return new IcsEvent(
            uid: 'nxwp-team-seg-' . $subject->id . '-' . $segment->id . '@nexwaypoint',
            summary: $summary,
            description: 'Trip: ' . ($canCity ? trim($trip->destinationCity) : 'Traveling'),
            location: $route !== '' ? $route : null,
            dtStart: $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            dtEnd: $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            allDay: false,
            categories: ['NexWAYPOINT', 'Team'],
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
