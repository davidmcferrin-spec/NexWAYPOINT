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
 * Builds visibility-filtered ICS events for teammates on a team calendar feed.
 */
final class TeamTravelFeedBuilder
{
    private const TRANSIT_TYPES = ['flight', 'train', 'car'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly TripRepository $trips,
        private readonly VisibilityEngine $visibility,
        private readonly VisibilityBlockRepository $blocks,
        private readonly ?AirportRepository $airports = null,
        private readonly int $daysAhead = 60,
    ) {
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
            foreach ($this->trips->findActiveOrUpcoming($subject->id, $this->daysAhead, $asOf) as $trip) {
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

                if ($canDates) {
                    $allDay = $this->tripAllDay($subject, $trip, $canCity, $canPurpose);
                    if ($allDay !== null) {
                        $events[] = $allDay;
                    }
                }

                // Timed legs only when flight identity is visible — city+dates
                // alone stays on the all-day trip block (BOTTOM_UP default).
                if ($canFlight || $canCarrier) {
                    foreach ($this->trips->segmentsForTrip($trip->id) as $segment) {
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
        $summary = $city !== null ? $name . ' · ' . $city : $name . ' · Traveling';

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
        if (!in_array($segment->segmentType, self::TRANSIT_TYPES, true)) {
            return null;
        }
        if ($segment->departDt === null || $segment->arriveDt === null) {
            return null;
        }
        // Need at least some flight identity or city to be worth a timed event.
        if (!$canFlight && !$canCarrier && !$canCity) {
            return null;
        }

        $start = $this->instant($segment->origin, $segment->departDt);
        $end = $this->instant($segment->destination, $segment->arriveDt);
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
            $route = $this->routeLabel($segment->origin, $segment->destination);
            if ($route !== '') {
                $bits[] = $route;
            }
        }

        $summary = implode(' · ', array_filter($bits, static fn (string $b): bool => $b !== ''));
        if ($summary === $subject->displayName) {
            $summary .= ' · Flight';
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

    private function instant(?string $airportCode, string $naiveDt): \DateTimeImmutable
    {
        if ($this->airports !== null) {
            return $this->airports->instant($airportCode, $naiveDt);
        }
        return new \DateTimeImmutable($naiveDt);
    }

    private function routeLabel(?string $origin, ?string $destination): string
    {
        if ($this->airports !== null) {
            return $this->airports->routeLabel($origin, $destination);
        }
        $o = $origin !== null && $origin !== '' ? $origin : '?';
        $d = $destination !== null && $destination !== '' ? $destination : '?';
        return $o . ' → ' . $d;
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
