<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripSegment;

/**
 * Derives "in city" calendar windows between transit legs (and until trip end
 * when the last arrival is not a re-base to home). Gaps ≤3h are treated as
 * layovers and skipped — same threshold as TripStatusEngine.
 */
final class TravelPresencePlanner
{
    public const TRANSIT_TYPES = ['flight', 'train', 'car'];

    /** Match TripStatusEngine layover vs itinerary-remote split. */
    private const MIN_GAP_SECONDS = 3 * 3600;

    public function __construct(
        private readonly ?AirportRepository $airports = null,
    ) {
    }

    /**
     * Active transit legs sorted by departure (cancelled / incomplete skipped).
     *
     * @param list<TripSegment> $segments
     * @return list<TripSegment>
     */
    public function transitLegs(array $segments): array
    {
        $transit = [];
        foreach ($segments as $segment) {
            if ($segment->status === 'cancelled') {
                continue;
            }
            if (!in_array($segment->segmentType, self::TRANSIT_TYPES, true)) {
                continue;
            }
            if ($segment->departDt === null || $segment->arriveDt === null || $segment->id === null) {
                continue;
            }
            $transit[] = $segment;
        }
        usort(
            $transit,
            static fn (TripSegment $a, TripSegment $b): int => strcmp(
                (string) $a->departDt,
                (string) $b->departDt
            )
        );
        return $transit;
    }

    /**
     * @param list<TripSegment> $segments All segments for the trip (any types).
     * @return list<array{
     *   after_segment_id: int,
     *   city: string,
     *   start: \DateTimeImmutable,
     *   end: \DateTimeImmutable
     * }>
     */
    public function presenceWindows(
        array $segments,
        Trip $trip,
        ?string $homeCity = null,
        ?string $homeState = null,
    ): array {
        $transit = $this->transitLegs($segments);
        if ($transit === []) {
            return [];
        }

        $windows = [];
        $count = count($transit);
        for ($i = 0; $i < $count; $i++) {
            $leg = $transit[$i];
            $arrive = $this->instant($leg->destination, (string) $leg->arriveDt);
            $city = $this->cityLabel($leg->destination, $trip);
            if ($city === '') {
                continue;
            }

            $next = $transit[$i + 1] ?? null;
            if ($next !== null) {
                $end = $this->instant($next->origin, (string) $next->departDt);
            } else {
                // Last arrival: skip if re-basing home; otherwise hold city through trip end.
                if ($this->isHomeCity($city, $homeCity, $homeState)) {
                    continue;
                }
                $end = $this->tripEndInstant($trip, $leg->destination);
            }

            if ($end <= $arrive) {
                continue;
            }
            if (($end->getTimestamp() - $arrive->getTimestamp()) < self::MIN_GAP_SECONDS) {
                continue;
            }

            $windows[] = [
                'after_segment_id' => (int) $leg->id,
                'city' => $city,
                'start' => $arrive,
                'end' => $end,
            ];
        }

        return $windows;
    }

    public function instant(?string $airportCode, string $naiveDt): \DateTimeImmutable
    {
        if ($this->airports !== null) {
            return $this->airports->instant($airportCode, $naiveDt);
        }
        return new \DateTimeImmutable($naiveDt);
    }

    public function routeLabel(?string $origin, ?string $destination): string
    {
        if ($this->airports !== null) {
            return $this->airports->routeLabel($origin, $destination);
        }
        $o = $origin !== null && $origin !== '' ? $origin : '?';
        $d = $destination !== null && $destination !== '' ? $destination : '?';
        return $o . ' → ' . $d;
    }

    private function cityLabel(?string $airportCode, Trip $trip): string
    {
        if ($this->airports !== null) {
            $city = $this->airports->cityFor($airportCode);
            if ($city !== null && trim($city) !== '') {
                return trim($city);
            }
        }
        $tripCity = trim($trip->destinationCity);
        if ($tripCity !== '') {
            return $tripCity;
        }
        return trim((string) $airportCode);
    }

    private function tripEndInstant(Trip $trip, ?string $lastAirport): \DateTimeImmutable
    {
        // End of trip end_date in the last arrival airport's timezone.
        $tzName = $this->airports?->timezoneForCode($lastAirport) ?? date_default_timezone_get();
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception) {
            $tz = new \DateTimeZone(date_default_timezone_get());
        }
        try {
            $day = new \DateTimeImmutable($trip->endDate, $tz);
        } catch (\Exception) {
            $day = new \DateTimeImmutable('today', $tz);
        }
        return $day->setTime(23, 59, 59);
    }

    private function isHomeCity(string $place, ?string $homeCity, ?string $homeState): bool
    {
        $place = strtolower(trim($place));
        $homeCity = $homeCity !== null ? strtolower(trim($homeCity)) : '';
        if ($place === '' || $homeCity === '') {
            return false;
        }
        $homeState = $homeState !== null ? strtolower(trim($homeState)) : '';
        if ($homeState !== '' && $place === $homeCity . ', ' . $homeState) {
            return true;
        }
        if (str_starts_with($place, $homeCity . ',')) {
            return true;
        }
        return $place === $homeCity;
    }
}
