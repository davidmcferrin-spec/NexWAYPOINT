<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

/**
 * Builds ICS events for the feed owner's own travel (full detail).
 */
final class PersonalTravelFeedBuilder
{
    private const TRANSIT_TYPES = ['flight', 'train', 'car'];

    public function __construct(
        private readonly TripRepository $trips,
        private readonly ?AirportRepository $airports = null,
        private readonly int $daysAhead = 90,
    ) {
    }

    /**
     * @return list<IcsEvent>
     */
    public function buildEvents(int $ownerUserId, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('today');
        $events = [];

        foreach ($this->trips->findActiveOrUpcoming($ownerUserId, $this->daysAhead, $asOf) as $trip) {
            if ($trip->id === null || $trip->status === 'cancelled') {
                continue;
            }
            $events[] = $this->tripAllDay($trip);
            foreach ($this->trips->segmentsForTrip($trip->id) as $segment) {
                $timed = $this->transitEvent($segment, $trip);
                if ($timed !== null) {
                    $events[] = $timed;
                }
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

    private function transitEvent(TripSegment $segment, Trip $trip): ?IcsEvent
    {
        if ($segment->status === 'cancelled') {
            return null;
        }
        if (!in_array($segment->segmentType, self::TRANSIT_TYPES, true)) {
            return null;
        }
        if ($segment->departDt === null || $segment->arriveDt === null || $segment->id === null) {
            return null;
        }

        $start = $this->instant($segment->origin, $segment->departDt);
        $end = $this->instant($segment->destination, $segment->arriveDt);
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
        $route = $this->routeLabel($segment->origin, $segment->destination);
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
