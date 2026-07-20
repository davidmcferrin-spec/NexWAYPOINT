<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Logger;

/**
 * Resolves a single user's current status string for the dashboard:
 * "Home", "Office", "Remote", "In Flight (ORD -> LAX)", "Layover in Denver",
 * "Delayed - ORD -> LAX", "At hotel in Chicago".
 *
 * Precedence: an active flight/train/car segment covering "now" wins over
 * everything else; a layover between two segments of the same trip wins
 * over a manual override; a manual user_status_overrides row (home/office/
 * remote/unavailable) covering "now" through expires_on is used when there's
 * no active travel; "Home" is the default when nothing else applies.
 */
final class TripStatusEngine
{
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

        $activeTrips = $this->trips->findActiveOrUpcoming($userId, 2, $now);
        $allSegments = [];
        foreach ($activeTrips as $trip) {
            foreach ($this->trips->segmentsForTrip((int) $trip->id) as $segment) {
                $allSegments[] = $segment;
            }
        }

        // 1. Currently inside a flight/train segment's depart->arrive window.
        foreach ($allSegments as $segment) {
            if ($segment->segmentType === 'hotel' || $segment->departDt === null || $segment->arriveDt === null) {
                continue;
            }
            $depart = new \DateTimeImmutable($segment->departDt);
            $arrive = new \DateTimeImmutable($segment->arriveDt);

            if ($now >= $depart && $now <= $arrive) {
                if ($segment->status === 'cancelled') {
                    return $this->result('cancelled', "Cancelled: {$segment->origin} -> {$segment->destination}", $segment);
                }
                if ($segment->status === 'delayed') {
                    return $this->result('delayed', "Delayed: {$segment->origin} -> {$segment->destination}", $segment);
                }
                $verb = $segment->segmentType === 'flight' ? 'In Flight' : 'In Transit';
                return $this->result('en_route', "{$verb}: {$segment->origin} -> {$segment->destination}", $segment);
            }
        }

        // 2. Layover: between the arrival of one segment and the departure of
        // the next segment on the same trip, both same-day.
        $byTrip = [];
        foreach ($allSegments as $segment) {
            $byTrip[$segment->tripId][] = $segment;
        }
        foreach ($byTrip as $segments) {
            usort($segments, static fn (TripSegment $a, TripSegment $b) => strcmp((string) $a->departDt, (string) $b->departDt));
            for ($i = 0; $i < count($segments) - 1; $i++) {
                $current = $segments[$i];
                $next = $segments[$i + 1];
                if ($current->arriveDt === null || $next->departDt === null) {
                    continue;
                }
                $arrive = new \DateTimeImmutable($current->arriveDt);
                $nextDepart = new \DateTimeImmutable($next->departDt);
                if ($now >= $arrive && $now <= $nextDepart) {
                    $city = $current->destination ?? 'transit';
                    return $this->result('layover', "Layover in {$city}", $current);
                }
            }
        }

        // 3. Inside a hotel stay window -> "At hotel in {city}".
        foreach ($allSegments as $segment) {
            if ($segment->segmentType !== 'hotel' || $segment->departDt === null || $segment->arriveDt === null) {
                continue;
            }
            $checkIn = new \DateTimeImmutable($segment->departDt);
            $checkOut = new \DateTimeImmutable($segment->arriveDt);
            if ($now >= $checkIn && $now <= $checkOut) {
                $city = $segment->destination ?? $segment->origin ?? 'destination';
                return $this->result('at_hotel', "At hotel in {$city}", $segment);
            }
        }

        // 4. No active travel -- fall back to the manual status override
        // (covers today when effective_date <= today <= expires_on).
        $override = $this->trips->activeStatusOverride($userId, $now);
        if ($override !== null) {
            $labels = ['home' => 'Home', 'office' => 'Office', 'remote' => 'Working Remote', 'unavailable' => 'Unavailable'];
            $status = (string) $override['status'];
            $expiresOn = $override['expires_on'] ?? $override['effective_date'] ?? null;
            return [
                'status' => $status,
                'label' => $labels[$status] ?? ucfirst($status),
                'detail' => [
                    'note' => $override['note'] ?? null,
                    'override' => true,
                    'effective_date' => $override['effective_date'] ?? null,
                    'expires_on' => $expiresOn,
                ],
            ];
        }

        // 5. Default.
        return ['status' => 'home', 'label' => 'Home', 'detail' => []];
    }

    /**
     * @return array{status: string, label: string, detail: array<string, mixed>}
     */
    private function result(string $status, string $label, TripSegment $segment): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'detail' => [
                'segment_id' => $segment->id,
                'trip_id' => $segment->tripId,
                'carrier' => $segment->carrier,
                'confirmation_code' => $segment->confirmationCode,
            ],
        ];
    }
}
