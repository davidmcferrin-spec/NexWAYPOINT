<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Logger;

/**
 * Resolves a user's current status along a multi-leg itinerary.
 *
 * Transit times on segments are naive local wall-clock strings. Depart is
 * interpreted in the origin airport timezone; arrive in the destination
 * airport timezone (IATA via AirportRepository). Unknown codes fall back
 * to APP_TIMEZONE. Hotel windows stay in APP_TIMEZONE (city labels, not IATA).
 *
 * Transit timeline:
 *   pre_flight  — [depart − 45m, depart)
 *   en_route    — [depart, arrive]  (delayed/cancelled override inside)
 *   post_flight — (arrive, arrive + 45m]
 *   layover     — after post, until next depart, when gap ≤ 3 hours
 *   remote      — after post, until next depart, when gap > 3 hours
 *                 (Working Remote · {arrived city})
 *   at_hotel    — hotel segment check-in→check-out window
 *
 * Manual overrides apply only when no travel phase matches; then Home.
 */
final class TripStatusEngine
{
    public const PRE_FLIGHT_MINUTES = 45;
    public const POST_FLIGHT_MINUTES = 45;
    public const LAYOVER_MAX_HOURS = 3;

    public function __construct(
        private readonly TripRepository $trips,
        private readonly Logger $logger,
        private readonly ?AirportRepository $airports = null,
    ) {
    }

    /**
     * @return array{status: string, label: string, detail: array<string, mixed>}
     */
    public function resolveForUser(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now');

        // Load trips that could still be in progress (long business trips).
        $activeTrips = $this->trips->findActiveOrUpcoming($userId, 60, $now);
        $allSegments = [];
        foreach ($activeTrips as $trip) {
            foreach ($this->trips->segmentsForTrip((int) $trip->id) as $segment) {
                $allSegments[] = $segment;
            }
        }

        $travel = $this->resolveTravelPhase($allSegments, $now);
        if ($travel !== null) {
            return $travel;
        }

        $override = $this->trips->activeStatusOverride($userId, $now);
        if ($override !== null) {
            $labels = ['home' => 'Home', 'office' => 'Office', 'remote' => 'Working Remote', 'unavailable' => 'Unavailable'];
            $status = (string) $override['status'];
            $expiresOn = $override['expires_on'] ?? $override['effective_date'] ?? null;
            $locationCity = isset($override['location_city']) && $override['location_city'] !== ''
                ? (string) $override['location_city']
                : null;
            $locationState = isset($override['location_state']) && $override['location_state'] !== ''
                ? (string) $override['location_state']
                : null;
            $label = $labels[$status] ?? ucfirst($status);
            if ($status === 'remote' && $locationCity !== null) {
                $place = $locationState !== null ? "{$locationCity}, {$locationState}" : $locationCity;
                $label = "Working Remote · {$place}";
            }
            return [
                'status' => $status,
                'label' => $label,
                'detail' => [
                    'note' => $override['note'] ?? null,
                    'override' => true,
                    'effective_date' => $override['effective_date'] ?? null,
                    'expires_on' => $expiresOn,
                    'location_city' => $locationCity,
                    'location_state' => $locationState,
                ],
            ];
        }

        return ['status' => 'home', 'label' => 'Home', 'detail' => []];
    }

    /**
     * @param TripSegment[] $allSegments
     * @return array{status: string, label: string, detail: array<string, mixed>}|null
     */
    private function resolveTravelPhase(array $allSegments, \DateTimeImmutable $now): ?array
    {
        $byTrip = [];
        foreach ($allSegments as $segment) {
            if ($segment->status === 'cancelled') {
                continue;
            }
            $byTrip[$segment->tripId][] = $segment;
        }

        foreach ($byTrip as $segments) {
            usort(
                $segments,
                static fn (TripSegment $a, TripSegment $b) => strcmp((string) $a->departDt, (string) $b->departDt)
            );

            $transit = [];
            foreach ($segments as $segment) {
                if (in_array($segment->segmentType, ['flight', 'train', 'car'], true)
                    && $segment->departDt !== null
                    && $segment->arriveDt !== null
                ) {
                    $transit[] = $segment;
                }
            }

            for ($i = 0; $i < count($transit); $i++) {
                $segment = $transit[$i];
                $depart = $this->departInstant($segment);
                $arrive = $this->arriveInstant($segment);
                $preStart = $depart->modify('-' . self::PRE_FLIGHT_MINUTES . ' minutes');
                $postEnd = $arrive->modify('+' . self::POST_FLIGHT_MINUTES . ' minutes');

                if ($now >= $preStart && $now < $depart) {
                    $verb = $segment->segmentType === 'flight' ? 'Pre-flight' : 'Pre-departure';
                    $route = $this->routeLabel($segment);
                    return $this->result(
                        'pre_flight',
                        "{$verb}: {$route}",
                        $segment,
                        ['location_city' => $this->locationCity($segment->destination)]
                    );
                }

                if ($now >= $depart && $now <= $arrive) {
                    $route = $this->routeLabel($segment);
                    if ($segment->status === 'cancelled') {
                        return $this->result('cancelled', "Cancelled: {$route}", $segment, [
                            'location_city' => $this->locationCity($segment->destination),
                        ]);
                    }
                    if ($segment->status === 'delayed') {
                        return $this->result('delayed', "Delayed: {$route}", $segment, [
                            'location_city' => $this->locationCity($segment->destination),
                        ]);
                    }
                    $verb = $segment->segmentType === 'flight' ? 'In Flight' : 'In Transit';
                    return $this->result(
                        'en_route',
                        "{$verb}: {$route}",
                        $segment,
                        ['location_city' => $this->locationCity($segment->destination)]
                    );
                }

                if ($now > $arrive && $now <= $postEnd) {
                    $city = $this->locationCity($segment->destination) ?? 'destination';
                    $postLabel = $segment->segmentType === 'flight'
                        ? "Post-flight: arrived {$city}"
                        : "Post-arrival: arrived {$city}";
                    return $this->result(
                        'post_flight',
                        $postLabel,
                        $segment,
                        ['location_city' => $this->locationCity($segment->destination)]
                    );
                }

                $next = $transit[$i + 1] ?? null;
                if ($next === null || $next->departDt === null) {
                    // Last arrival with no return: stay in that city until the
                    // trip drops out of the active window (end_date), instead
                    // of snapping to Home 45 minutes after landing.
                    if ($now > $postEnd) {
                        $openEnded = $this->openEndedLastCity($transit, $segment, $segments, $now);
                        if ($openEnded !== null) {
                            return $openEnded;
                        }
                    }
                    continue;
                }
                $nextDepart = $this->departInstant($next);
                if ($now <= $postEnd || $now >= $nextDepart) {
                    continue;
                }

                $gapSeconds = $nextDepart->getTimestamp() - $arrive->getTimestamp();
                $city = $this->locationCity($segment->destination) ?? 'transit';
                if ($gapSeconds <= self::LAYOVER_MAX_HOURS * 3600) {
                    return $this->result(
                        'layover',
                        "Layover in {$city}",
                        $segment,
                        ['location_city' => $this->locationCity($segment->destination)]
                    );
                }

                // Long gap: hotel stay on this trip wins over itinerary remote.
                $hotelHit = $this->hotelAt($segments, $now);
                if ($hotelHit !== null) {
                    return $hotelHit;
                }

                return $this->result(
                    'remote',
                    "Working Remote · {$city}",
                    $segment,
                    [
                        'location_city' => $this->locationCity($segment->destination),
                        'location_state' => null,
                        'from_itinerary' => true,
                    ]
                );
            }

            $hotelHit = $this->hotelAt($segments, $now);
            if ($hotelHit !== null) {
                return $hotelHit;
            }
        }

        return null;
    }

    /**
     * After the last transit leg, keep itinerary-remote at the arrived city
     * when this is not a re-base to the trip's first origin.
     *
     * @param TripSegment[] $transit
     * @param TripSegment[] $segments
     * @return array{status: string, label: string, detail: array<string, mixed>}|null
     */
    private function openEndedLastCity(
        array $transit,
        TripSegment $segment,
        array $segments,
        \DateTimeImmutable $now,
    ): ?array {
        $firstOrigin = strtoupper(trim((string) ($transit[0]->origin ?? '')));
        $destCode = strtoupper(trim((string) ($segment->destination ?? '')));
        if ($destCode === '' || ($firstOrigin !== '' && $destCode === $firstOrigin)) {
            return null;
        }

        $hotelHit = $this->hotelAt($segments, $now);
        if ($hotelHit !== null) {
            return $hotelHit;
        }

        $city = $this->locationCity($segment->destination) ?? 'destination';
        return $this->result(
            'remote',
            "Working Remote · {$city}",
            $segment,
            [
                'location_city' => $this->locationCity($segment->destination),
                'location_state' => null,
                'from_itinerary' => true,
            ]
        );
    }

    /**
     * @param TripSegment[] $segments
     * @return array{status: string, label: string, detail: array<string, mixed>}|null
     */
    private function hotelAt(array $segments, \DateTimeImmutable $now): ?array
    {
        foreach ($segments as $segment) {
            if ($segment->segmentType !== 'hotel' || $segment->departDt === null || $segment->arriveDt === null) {
                continue;
            }
            // Hotel stays are city-local wall clocks in APP_TIMEZONE (not airport IATA).
            $checkIn = new \DateTimeImmutable($segment->departDt);
            $checkOut = new \DateTimeImmutable($segment->arriveDt);
            if ($now >= $checkIn && $now <= $checkOut) {
                $city = $segment->destination ?? $segment->origin ?? 'destination';
                return $this->result('at_hotel', "At hotel in {$city}", $segment, [
                    'location_city' => $segment->destination ?? $segment->origin,
                ]);
            }
        }
        return null;
    }

    private function departInstant(TripSegment $segment): \DateTimeImmutable
    {
        return $this->wallClockInstant($segment->origin, (string) $segment->departDt);
    }

    private function arriveInstant(TripSegment $segment): \DateTimeImmutable
    {
        return $this->wallClockInstant($segment->destination, (string) $segment->arriveDt);
    }

    private function wallClockInstant(?string $airportCode, string $naiveDt): \DateTimeImmutable
    {
        if ($this->airports !== null) {
            return $this->airports->instant($airportCode, $naiveDt);
        }
        return new \DateTimeImmutable($naiveDt);
    }

    private function routeLabel(TripSegment $segment): string
    {
        if ($this->airports !== null) {
            return $this->airports->routeLabel($segment->origin, $segment->destination, ' -> ');
        }
        return trim(($segment->origin ?? '?') . ' -> ' . ($segment->destination ?? '?'));
    }

    private function locationCity(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        if ($this->airports !== null) {
            return $this->airports->cityFor($code) ?? $code;
        }
        return $code;
    }

    /**
     * @param array<string, mixed> $extraDetail
     * @return array{status: string, label: string, detail: array<string, mixed>}
     */
    private function result(string $status, string $label, TripSegment $segment, array $extraDetail = []): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'detail' => array_merge([
                'segment_id' => $segment->id,
                'trip_id' => $segment->tripId,
                'carrier' => $segment->carrier,
                'confirmation_code' => $segment->confirmationCode,
                'hotel_stay_id' => $segment->hotelStayId,
                'origin' => $segment->origin,
                'destination' => $segment->destination,
            ], $extraDetail),
        ];
    }
}
