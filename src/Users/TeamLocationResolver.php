<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

/**
 * Resolves lat/lon + city label for a teammate on the dashboard map.
 *
 * Home/office/unavailable → profile home city.
 * Remote → override location (fallback home).
 * Travel → hotel property coords or geocoded destination city.
 * Bare IATA destination_city values (e.g. DFW) expand via AirportRepository
 * to labels like "Dallas/Fort Worth, TX (DFW)".
 * Returns null when destination detail is visibility-redacted or unresolved.
 *
 * "Next" is the soonest location change a viewer should care about: return
 * Home (re-base) while away, otherwise the next visible trip destination.
 * Dates include an early/afternoon/evening/late bucket when a wall-clock
 * depart/arrive time is known.
 */
final class TeamLocationResolver
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly HotelStayRepository $stays,
        private readonly HotelPropertyRepository $properties,
        private readonly Geocoder $geocoder,
        private readonly ?AirportRepository $airports = null,
    ) {
    }

    /**
     * @param array{status: string, label: string, detail: array<string, mixed>} $status
     * @return array{lat: float, lon: float, city_label: string, city_key: string}|null
     */
    public function resolve(User $user, array $status, bool $destinationVisible = true): ?array
    {
        $code = $status['status'];
        $detail = $status['detail'] ?? [];

        if (in_array($code, ['home', 'office', 'unavailable'], true)) {
            return $this->fromHome($user);
        }

        if ($code === 'remote') {
            $city = isset($detail['location_city']) ? trim((string) $detail['location_city']) : '';
            $state = isset($detail['location_state']) ? trim((string) $detail['location_state']) : '';
            if ($city !== '') {
                return $this->fromCityState($city, $state !== '' ? $state : null);
            }
            return $this->fromHome($user);
        }

        // Travel statuses: omit pin when destination city is not visible to viewer.
        if (!$destinationVisible) {
            return null;
        }

        if ($code === 'at_hotel') {
            $stayId = isset($detail['hotel_stay_id']) ? (int) $detail['hotel_stay_id'] : 0;
            if ($stayId > 0) {
                $fromStay = $this->fromHotelStay($stayId);
                if ($fromStay !== null) {
                    return $fromStay;
                }
            }
            $dest = isset($detail['destination']) ? trim((string) $detail['destination']) : '';
            if ($dest !== '') {
                return $this->fromCityState($dest, null);
            }
        }

        // Pin at the city for the active phase (leg dest / layover / post-arrival).
        if (in_array($code, ['pre_flight', 'en_route', 'post_flight', 'layover', 'delayed', 'cancelled'], true)) {
            $phaseCity = isset($detail['location_city']) ? trim((string) $detail['location_city']) : '';
            if ($phaseCity === '') {
                $phaseCity = isset($detail['destination']) ? trim((string) $detail['destination']) : '';
            }
            if ($phaseCity !== '') {
                return $this->fromCityState($phaseCity, null);
            }
            $tripId = isset($detail['trip_id']) ? (int) $detail['trip_id'] : 0;
            if ($tripId > 0) {
                $trip = $this->trips->find($tripId);
                if ($trip !== null && trim($trip->destinationCity) !== '') {
                    return $this->fromCityState($trip->destinationCity, null);
                }
            }
        }

        return null;
    }

    /**
     * Resolve current pin; attach Next as the soonest location change
     * (return Home while away, else next visible trip). Pin stays where they are now.
     *
     * @param array{status: string, label: string, detail: array<string, mixed>} $status
     * @return array{
     *   location: array{lat: float, lon: float, city_label: string, city_key: string}|null,
     *   upcoming: string|null,
     *   next: array{city_label: string, dates: string, time_of_day: string|null}|null
     * }
     */
    public function resolveWithUpcoming(
        User $user,
        array $status,
        bool $destinationVisible,
        ?Trip $upcomingVisibleTrip,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable('now');
        $location = $this->resolve($user, $status, $destinationVisible);
        $detail = $status['detail'] ?? [];
        $activeTripId = isset($detail['trip_id']) ? (int) $detail['trip_id'] : 0;
        $atBase = self::isAtBaseStatus($status['status'], $detail);

        /** @var list<array{sort_at: \DateTimeImmutable, next: array{city_label: string, dates: string, time_of_day: string|null}}> $candidates */
        $candidates = [];

        if (!$atBase && $activeTripId > 0) {
            $homeNext = $this->candidateReturnHome($user, $activeTripId, $now, $status['status']);
            if ($homeNext !== null) {
                $candidates[] = $homeNext;
            }
        }

        if (
            $upcomingVisibleTrip !== null
            && trim($upcomingVisibleTrip->destinationCity) !== ''
            && (int) ($upcomingVisibleTrip->id ?? 0) !== $activeTripId
        ) {
            $tripNext = $this->candidateUpcomingTrip($upcomingVisibleTrip);
            if ($tripNext !== null) {
                $candidates[] = $tripNext;
            }
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => $a['sort_at'] <=> $b['sort_at']
        );

        $next = $candidates[0]['next'] ?? null;
        $upcomingLabel = $next !== null ? self::formatNextSummary($next) : null;

        return [
            'location' => $location,
            'upcoming' => $upcomingLabel,
            'next' => $next,
        ];
    }

    public static function formatTripDateRange(string $start, string $end): string
    {
        try {
            $startDt = new \DateTimeImmutable($start);
            $endDt = new \DateTimeImmutable($end);
        } catch (\Exception) {
            return $start . '–' . $end;
        }
        if ($startDt->format('Y-m') === $endDt->format('Y-m')) {
            return $startDt->format('M j') . '–' . $endDt->format('j');
        }
        return $startDt->format('M j') . '–' . $endDt->format('M j');
    }

    public static function formatSingleDate(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('M j');
        } catch (\Exception) {
            return $date;
        }
    }

    /**
     * Early ≤10:00, Afternoon 10:01–16:00, Evening 16:01–20:00, Late after 20:00.
     * Uses naive local wall-clock hour:minute from a segment datetime.
     */
    public static function timeOfDayBucket(?string $naiveDt): ?string
    {
        if ($naiveDt === null || trim($naiveDt) === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($naiveDt);
        } catch (\Exception) {
            return null;
        }
        $minutes = ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
        if ($minutes <= 10 * 60) {
            return 'Early';
        }
        if ($minutes <= 16 * 60) {
            return 'Afternoon';
        }
        if ($minutes <= 20 * 60) {
            return 'Evening';
        }
        return 'Late';
    }

    /**
     * @param array{city_label: string, dates: string, time_of_day?: string|null} $next
     */
    public static function formatNextSummary(array $next): string
    {
        $parts = [$next['city_label'], $next['dates']];
        $tod = $next['time_of_day'] ?? null;
        if ($tod !== null && $tod !== '') {
            $parts[] = $tod;
        }
        return implode(' · ', $parts);
    }

    /**
     * Dates (+ optional time-of-day) for the Next column hint line.
     *
     * @param array{city_label: string, dates: string, time_of_day?: string|null} $next
     */
    public static function formatNextDatesHint(array $next): string
    {
        $tod = $next['time_of_day'] ?? null;
        if ($tod !== null && $tod !== '') {
            return $next['dates'] . ' · ' . $tod;
        }
        return $next['dates'];
    }

    /**
     * @return array{sort_at: \DateTimeImmutable, next: array{city_label: string, dates: string, time_of_day: string|null}}|null
     */
    private function candidateReturnHome(
        User $user,
        int $tripId,
        \DateTimeImmutable $now,
        string $statusCode,
    ): ?array {
        $transit = $this->transitSegmentsForTrip($tripId);
        if ($transit === []) {
            // Hotel-only trips: treat checkout / trip end as re-base. Do not
            // invent Home for transit-less rows that are somehow mid-status.
            if ($statusCode !== 'at_hotel') {
                return null;
            }
            $trip = $this->trips->find($tripId);
            if ($trip === null || trim($trip->endDate) === '') {
                return null;
            }
            try {
                $endDay = new \DateTimeImmutable($trip->endDate . ' 23:59:59');
            } catch (\Exception) {
                return null;
            }
            if ($endDay <= $now) {
                return null;
            }
            return [
                'sort_at' => $endDay,
                'next' => [
                    'city_label' => 'Home',
                    'dates' => self::formatSingleDate($trip->endDate),
                    'time_of_day' => null,
                ],
            ];
        }

        $firstOrigin = strtoupper(trim((string) ($transit[0]->origin ?? '')));
        $last = $transit[count($transit) - 1];
        $lastDest = strtoupper(trim((string) ($last->destination ?? '')));
        if ($lastDest === '' || $last->arriveDt === null) {
            return null;
        }

        $returnsHome = ($firstOrigin !== '' && $firstOrigin === $lastDest)
            || $this->airportMatchesHome($lastDest, $user);
        if (!$returnsHome) {
            return null;
        }

        $arriveInstant = $this->wallClockInstant($last->destination, (string) $last->arriveDt);
        if ($arriveInstant <= $now) {
            return null;
        }

        return [
            'sort_at' => $arriveInstant,
            'next' => [
                'city_label' => 'Home',
                'dates' => self::formatSingleDate((string) $last->arriveDt),
                'time_of_day' => self::timeOfDayBucket((string) $last->arriveDt),
            ],
        ];
    }

    /**
     * @return array{sort_at: \DateTimeImmutable, next: array{city_label: string, dates: string, time_of_day: string|null}}|null
     */
    private function candidateUpcomingTrip(Trip $trip): ?array
    {
        $upcomingPin = $this->resolveUpcomingDestination($trip->destinationCity);
        if ($upcomingPin === null) {
            return null;
        }

        $timeOfDay = null;
        $sortAt = null;
        $transit = $this->transitSegmentsForTrip((int) ($trip->id ?? 0));
        if ($transit !== [] && $transit[0]->departDt !== null) {
            $timeOfDay = self::timeOfDayBucket((string) $transit[0]->departDt);
            $sortAt = $this->wallClockInstant($transit[0]->origin, (string) $transit[0]->departDt);
        }

        if ($sortAt === null) {
            try {
                $sortAt = new \DateTimeImmutable($trip->startDate . ' 00:00:00');
            } catch (\Exception) {
                $sortAt = new \DateTimeImmutable('now');
            }
        }

        return [
            'sort_at' => $sortAt,
            'next' => [
                'city_label' => $upcomingPin['city_label'],
                'dates' => self::formatTripDateRange($trip->startDate, $trip->endDate),
                'time_of_day' => $timeOfDay,
            ],
        ];
    }

    /**
     * @return TripSegment[]
     */
    private function transitSegmentsForTrip(int $tripId): array
    {
        if ($tripId <= 0) {
            return [];
        }
        $segments = [];
        foreach ($this->trips->segmentsForTrip($tripId) as $segment) {
            if ($segment->status === 'cancelled') {
                continue;
            }
            if (!in_array($segment->segmentType, ['flight', 'train', 'car'], true)) {
                continue;
            }
            if ($segment->departDt === null || $segment->arriveDt === null) {
                continue;
            }
            $segments[] = $segment;
        }
        usort(
            $segments,
            static fn (TripSegment $a, TripSegment $b): int => strcmp((string) $a->departDt, (string) $b->departDt)
        );
        return $segments;
    }

    private function wallClockInstant(?string $airportCode, string $naiveDt): \DateTimeImmutable
    {
        if ($this->airports !== null) {
            return $this->airports->instant($airportCode, $naiveDt);
        }
        try {
            return new \DateTimeImmutable($naiveDt);
        } catch (\Exception) {
            return new \DateTimeImmutable('now');
        }
    }

    private function airportMatchesHome(string $iata, User $user): bool
    {
        if ($this->airports === null || $user->homeCity === null || trim($user->homeCity) === '') {
            return false;
        }
        $place = $this->airports->cityFor($iata);
        if ($place === null) {
            return false;
        }
        $homeCity = strtolower(trim($user->homeCity));
        $homeState = $user->homeState !== null ? strtolower(trim($user->homeState)) : '';
        $placeLower = strtolower($place);
        if ($homeState !== '' && $placeLower === $homeCity . ', ' . $homeState) {
            return true;
        }
        if (str_starts_with($placeLower, $homeCity . ',')) {
            return true;
        }
        return $placeLower === $homeCity;
    }

    /**
     * Geocode an upcoming trip destination for map/table pins.
     *
     * @return array{lat: float, lon: float, city_label: string, city_key: string}|null
     */
    public function resolveUpcomingDestination(string $destinationCity): ?array
    {
        return $this->fromCityState($destinationCity, null);
    }

    /**
     * Whether the current status is still at base (eligible for an upcoming label).
     * Mid-trip phases (including itinerary gap remote) are not at base.
     *
     * @param array<string, mixed> $detail
     */
    public static function isAtBaseStatus(string $status, array $detail = []): bool
    {
        if (in_array($status, [
            'pre_flight', 'en_route', 'post_flight', 'layover',
            'delayed', 'cancelled', 'at_hotel',
        ], true)) {
            return false;
        }
        if ($status === 'remote' && !empty($detail['from_itinerary'])) {
            return false;
        }

        return in_array($status, ['home', 'office', 'remote', 'unavailable'], true);
    }

    /**
     * @return array{lat: float, lon: float, city_label: string, city_key: string}|null
     */
    private function fromHome(User $user): ?array
    {
        if ($user->homeCity === null || $user->homeCity === '') {
            return null;
        }
        $label = $user->homeLabel() ?? $user->homeCity;
        $key = $this->cityKey($user->homeCity, $user->homeState);

        if ($user->homeLat !== null && $user->homeLon !== null) {
            return [
                'lat' => $user->homeLat,
                'lon' => $user->homeLon,
                'city_label' => $label,
                'city_key' => $key,
            ];
        }

        return $this->fromCityState($user->homeCity, $user->homeState);
    }

    /**
     * @return array{lat: float, lon: float, city_label: string, city_key: string}|null
     */
    private function fromHotelStay(int $stayId): ?array
    {
        $stay = $this->stays->find($stayId);
        if ($stay === null || $stay->hotelPropertyId <= 0) {
            return null;
        }
        $property = $this->properties->find($stay->hotelPropertyId);
        if ($property === null) {
            return null;
        }

        $city = trim((string) ($property->city ?? ''));
        $state = trim((string) ($property->stateRegion ?? ''));
        $label = $city;
        if ($state !== '') {
            $label = $label !== '' ? $label . ', ' . $state : $state;
        }
        if ($label === '') {
            $label = $property->hotelName;
        }
        $key = $this->cityKey($city !== '' ? $city : $label, $state !== '' ? $state : null);

        if ($property->latitude !== null && $property->longitude !== null) {
            return [
                'lat' => (float) $property->latitude,
                'lon' => (float) $property->longitude,
                'city_label' => $label,
                'city_key' => $key,
            ];
        }

        if ($city === '') {
            return null;
        }
        return $this->fromCityState($city, $state !== '' ? $state : null);
    }

    /**
     * @return array{lat: float, lon: float, city_label: string, city_key: string}|null
     */
    private function fromCityState(string $city, ?string $state): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }

        $displayLabel = null;
        $parsedCity = $city;
        $parsedState = $state;

        // Bare IATA (trips.destination_city from mail/builder) → friendly label + city geocode.
        $iata = AirportRepository::normalizeIata($city);
        if (
            $this->airports !== null
            && $iata !== null
            && $state === null
            && $this->airports->has($iata)
            && strtoupper(trim($city)) === $iata
        ) {
            $displayLabel = $this->airports->labelFor($iata);
            $place = $this->airports->cityFor($iata);
            if ($place !== null && preg_match('/^(.+?),\s*([A-Za-z]{2})$/', $place, $m) === 1) {
                $parsedCity = trim($m[1]);
                $parsedState = trim($m[2]);
            } elseif ($place !== null) {
                $parsedCity = $place;
                $parsedState = null;
            }
        } elseif ($parsedState === null && preg_match('/^(.+?),\s*([A-Za-z]{2}|[A-Za-z .]+)$/', $city, $m) === 1) {
            // destination_city may already be "Chicago, IL"
            $parsedCity = trim($m[1]);
            $parsedState = trim($m[2]);
        }

        $coords = $this->geocoder->geocodeCity($parsedCity, $parsedState, 'US');
        if ($coords === null && $displayLabel !== null && $parsedCity !== $city) {
            // Expanded city failed; last try the raw IATA / original string.
            $coords = $this->geocoder->geocodeCity($city, null, 'US');
        }
        if ($coords === null) {
            return null;
        }

        $label = $displayLabel ?? (
            $parsedState !== null && $parsedState !== ''
                ? "{$parsedCity}, {$parsedState}"
                : $parsedCity
        );

        return [
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
            'city_label' => $label,
            'city_key' => $this->cityKey($parsedCity, $parsedState),
        ];
    }

    private function cityKey(string $city, ?string $state): string
    {
        $city = strtolower(trim($city));
        $state = $state !== null ? strtolower(trim($state)) : '';
        return $city . '|' . $state;
    }
}
