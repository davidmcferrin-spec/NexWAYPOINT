<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\TripRepository;

/**
 * Resolves lat/lon + city label for a teammate on the dashboard map.
 *
 * Home/office/unavailable → profile home city.
 * Remote → override location (fallback home).
 * Travel → hotel property coords or geocoded destination city.
 * Returns null when destination detail is visibility-redacted or unresolved.
 */
final class TeamLocationResolver
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly HotelStayRepository $stays,
        private readonly HotelPropertyRepository $properties,
        private readonly Geocoder $geocoder,
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
     * Resolve current pin, then if at-base and a visible upcoming trip exists,
     * move the pin to that destination.
     *
     * @param array{status: string, label: string, detail: array<string, mixed>} $status
     * @return array{
     *   location: array{lat: float, lon: float, city_label: string, city_key: string}|null,
     *   upcoming: string|null
     * }
     */
    public function resolveWithUpcoming(
        User $user,
        array $status,
        bool $destinationVisible,
        ?\NexWaypoint\Trips\Trip $upcomingVisibleTrip,
    ): array {
        $location = $this->resolve($user, $status, $destinationVisible);
        $upcomingLabel = null;

        if (
            self::isAtBaseStatus($status['status'], $status['detail'] ?? [])
            && $upcomingVisibleTrip !== null
            && trim($upcomingVisibleTrip->destinationCity) !== ''
        ) {
            $upcomingPin = $this->resolveUpcomingDestination($upcomingVisibleTrip->destinationCity);
            if ($upcomingPin !== null) {
                $location = $upcomingPin;
                $upcomingLabel = $upcomingPin['city_label'] . ' · '
                    . self::formatTripDateRange($upcomingVisibleTrip->startDate, $upcomingVisibleTrip->endDate);
            }
        }

        return [
            'location' => $location,
            'upcoming' => $upcomingLabel,
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
     * Whether the current status is still at base (eligible for an upcoming-trip pin).
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
        // destination_city may already be "Chicago, IL"
        $parsedCity = $city;
        $parsedState = $state;
        if ($parsedState === null && preg_match('/^(.+?),\s*([A-Za-z]{2}|[A-Za-z .]+)$/', $city, $m) === 1) {
            $parsedCity = trim($m[1]);
            $parsedState = trim($m[2]);
        }

        $coords = $this->geocoder->geocodeCity($parsedCity, $parsedState, 'US');
        if ($coords === null) {
            return null;
        }

        $label = $parsedState !== null && $parsedState !== ''
            ? "{$parsedCity}, {$parsedState}"
            : $parsedCity;

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
