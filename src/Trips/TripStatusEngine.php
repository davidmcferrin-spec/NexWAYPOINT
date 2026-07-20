<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Logger;

/**
 * Resolves a user's current status along a multi-leg itinerary.
 *
 * Transit timeline (local wall-clock times on segments):
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
                $depart = new \DateTimeImmutable($segment->departDt);
                $arrive = new \DateTimeImmutable($segment->arriveDt);
                $preStart = $depart->modify('-' . self::PRE_FLIGHT_MINUTES . ' minutes');
                $postEnd = $arrive->modify('+' . self::POST_FLIGHT_MINUTES . ' minutes');

                if ($now >= $preStart && $now < $depart) {
                    $verb = $segment->segmentType === 'flight' ? 'Pre-flight' : 'Pre-departure';
                    return $this->result(
                        'pre_flight',
                        "{$verb}: {$segment->origin} -> {$segment->destination}",
                        $segment,
                        ['location_city' => $segment->destination]
                    );
                }

                if ($now >= $depart && $now <= $arrive) {
                    if ($segment->status === 'cancelled') {
                        return $this->result('cancelled', "Cancelled: {$segment->origin} -> {$segment->destination}", $segment, [
                            'location_city' => $segment->destination,
                        ]);
                    }
                    if ($segment->status === 'delayed') {
                        return $this->result('delayed', "Delayed: {$segment->origin} -> {$segment->destination}", $segment, [
                            'location_city' => $segment->destination,
                        ]);
                    }
                    $verb = $segment->segmentType === 'flight' ? 'In Flight' : 'In Transit';
                    return $this->result(
                        'en_route',
                        "{$verb}: {$segment->origin} -> {$segment->destination}",
                        $segment,
                        ['location_city' => $segment->destination]
                    );
                }

                if ($now > $arrive && $now <= $postEnd) {
                    $city = $segment->destination ?? 'destination';
                    $postLabel = $segment->segmentType === 'flight'
                        ? "Post-flight: arrived {$city}"
                        : "Post-arrival: arrived {$city}";
                    return $this->result(
                        'post_flight',
                        $postLabel,
                        $segment,
                        ['location_city' => $segment->destination]
                    );
                }

                $next = $transit[$i + 1] ?? null;
                if ($next === null || $next->departDt === null) {
                    continue;
                }
                $nextDepart = new \DateTimeImmutable($next->departDt);
                if ($now <= $postEnd || $now >= $nextDepart) {
                    continue;
                }

                $gapSeconds = $nextDepart->getTimestamp() - $arrive->getTimestamp();
                $city = $segment->destination ?? 'transit';
                if ($gapSeconds <= self::LAYOVER_MAX_HOURS * 3600) {
                    return $this->result(
                        'layover',
                        "Layover in {$city}",
                        $segment,
                        ['location_city' => $segment->destination]
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
                        'location_city' => $segment->destination,
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
     * @param TripSegment[] $segments
     * @return array{status: string, label: string, detail: array<string, mixed>}|null
     */
    private function hotelAt(array $segments, \DateTimeImmutable $now): ?array
    {
        foreach ($segments as $segment) {
            if ($segment->segmentType !== 'hotel' || $segment->departDt === null || $segment->arriveDt === null) {
                continue;
            }
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
