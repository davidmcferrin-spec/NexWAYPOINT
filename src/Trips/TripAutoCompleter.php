<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Logger;

/**
 * Marks planned/active trips completed after a re-base home has lasted
 * HOME_HOURS past the post-flight window. Last dest must equal first
 * origin (same test as TripStatusEngine). Open-ended last cities and
 * mid-trip hotel windows are left alone.
 */
final class TripAutoCompleter
{
    public const HOME_HOURS = 2;

    public function __construct(
        private readonly TripRepository $trips,
        private readonly Logger $logger,
        private readonly ?AirportRepository $airports = null,
    ) {
    }

    public function completeForOwner(int $ownerId, ?\DateTimeImmutable $now = null): int
    {
        return $this->completeTrips($this->trips->findOpen($ownerId), $now);
    }

    public function completeDue(?\DateTimeImmutable $now = null): int
    {
        return $this->completeTrips($this->trips->findOpen(), $now);
    }

    /**
     * @param Trip[] $trips
     */
    private function completeTrips(array $trips, ?\DateTimeImmutable $now): int
    {
        $now ??= new \DateTimeImmutable('now');
        $count = 0;
        foreach ($trips as $trip) {
            if ($trip->id === null || !in_array($trip->status, ['planned', 'active'], true)) {
                continue;
            }
            if (!$this->shouldComplete($trip, $now)) {
                continue;
            }
            $this->trips->markCompleted((int) $trip->id);
            $this->logger->info('Trip auto-completed after home dwell', [
                'trip_id' => $trip->id,
                'owner_id' => $trip->ownerId,
            ]);
            $count++;
        }
        return $count;
    }

    private function shouldComplete(Trip $trip, \DateTimeImmutable $now): bool
    {
        $segments = $this->trips->segmentsForTrip((int) $trip->id);
        if ($this->hotelCovers($segments, $now)) {
            return false;
        }

        $transit = $this->transitLegs($segments);
        if ($transit === []) {
            return $this->hotelOnlyHomeLongEnough($segments, $now);
        }

        $firstOrigin = strtoupper(trim((string) ($transit[0]->origin ?? '')));
        $last = $transit[count($transit) - 1];
        $lastDest = strtoupper(trim((string) ($last->destination ?? '')));
        if ($lastDest === '' || $firstOrigin === '' || $lastDest !== $firstOrigin) {
            return false;
        }
        if ($last->arriveDt === null) {
            return false;
        }

        $homeStart = $this->arriveInstant($last)->modify('+' . TripStatusEngine::POST_FLIGHT_MINUTES . ' minutes');
        $completeAt = $homeStart->modify('+' . self::HOME_HOURS . ' hours');
        return $now >= $completeAt;
    }

    /**
     * @param TripSegment[] $segments
     * @return TripSegment[]
     */
    private function transitLegs(array $segments): array
    {
        $transit = [];
        foreach ($segments as $segment) {
            if ($segment->status === 'cancelled') {
                continue;
            }
            if (!in_array($segment->segmentType, ['flight', 'train', 'car'], true)) {
                continue;
            }
            if ($segment->departDt === null || $segment->arriveDt === null) {
                continue;
            }
            $transit[] = $segment;
        }
        usort(
            $transit,
            static fn (TripSegment $a, TripSegment $b) => strcmp((string) $a->departDt, (string) $b->departDt)
        );
        return $transit;
    }

    /**
     * @param TripSegment[] $segments
     */
    private function hotelCovers(array $segments, \DateTimeImmutable $now): bool
    {
        foreach ($segments as $segment) {
            if ($segment->segmentType !== 'hotel' || $segment->status === 'cancelled') {
                continue;
            }
            if ($segment->departDt === null || $segment->arriveDt === null) {
                continue;
            }
            try {
                $checkIn = new \DateTimeImmutable($segment->departDt);
                $checkOut = new \DateTimeImmutable($segment->arriveDt);
            } catch (\Exception) {
                continue;
            }
            if ($now >= $checkIn && $now <= $checkOut) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param TripSegment[] $segments
     */
    private function hotelOnlyHomeLongEnough(array $segments, \DateTimeImmutable $now): bool
    {
        $lastCheckout = null;
        foreach ($segments as $segment) {
            if ($segment->segmentType !== 'hotel' || $segment->status === 'cancelled') {
                continue;
            }
            if ($segment->arriveDt === null) {
                continue;
            }
            try {
                $checkOut = new \DateTimeImmutable($segment->arriveDt);
            } catch (\Exception) {
                continue;
            }
            if ($lastCheckout === null || $checkOut > $lastCheckout) {
                $lastCheckout = $checkOut;
            }
        }
        if ($lastCheckout === null) {
            return false;
        }
        return $now >= $lastCheckout->modify('+' . self::HOME_HOURS . ' hours');
    }

    private function arriveInstant(TripSegment $segment): \DateTimeImmutable
    {
        if ($this->airports !== null) {
            return $this->airports->instant($segment->destination, (string) $segment->arriveDt);
        }
        return new \DateTimeImmutable((string) $segment->arriveDt);
    }
}
