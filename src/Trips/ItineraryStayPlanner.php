<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

/**
 * Overnight (or open-ended) arrivals on a multi-leg itinerary.
 *
 * Same 3-hour split as TripStatusEngine: a gap ≤3h is a connection/layover
 * and is not a destination stay. The last arrival is a stay unless it
 * returns to the first origin (round-trip re-base home).
 *
 * Used after parsing — does not change confirmation extraction.
 */
final class ItineraryStayPlanner
{
    public const MIN_GAP_SECONDS = 3 * 3600;

    /** @var list<string> */
    public const TRANSIT_TYPES = ['flight', 'train', 'car'];

    public function __construct(
        private readonly ?AirportRepository $airports = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $legs
     * @return list<array{
     *   origin: string,
     *   destination: string,
     *   depart_dt: string,
     *   arrive_dt: ?string
     * }>
     */
    public function staysFromLegArrays(array $legs): array
    {
        $normalized = [];
        foreach ($legs as $leg) {
            $type = strtolower(trim((string) ($leg['segment_type'] ?? 'flight')));
            if ($type !== '' && !in_array($type, self::TRANSIT_TYPES, true)) {
                continue;
            }
            $origin = strtoupper(trim((string) ($leg['origin'] ?? '')));
            $destination = strtoupper(trim((string) ($leg['destination'] ?? '')));
            $depart = trim((string) ($leg['depart_dt'] ?? ''));
            $arriveRaw = $leg['arrive_dt'] ?? null;
            $arrive = is_string($arriveRaw) && trim($arriveRaw) !== '' ? trim($arriveRaw) : null;
            if ($origin === '' || $destination === '' || $depart === '') {
                continue;
            }
            $normalized[] = [
                'origin' => $origin,
                'destination' => $destination,
                'depart_dt' => $depart,
                'arrive_dt' => $arrive,
            ];
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => strcmp($a['depart_dt'], $b['depart_dt'])
        );

        return $this->staysFromNormalized($normalized);
    }

    /**
     * @param TripSegment[] $segments
     * @return list<array{
     *   origin: string,
     *   destination: string,
     *   depart_dt: string,
     *   arrive_dt: ?string
     * }>
     */
    public function staysFromSegments(array $segments): array
    {
        $legs = [];
        foreach ($segments as $segment) {
            if ($segment->status === 'cancelled') {
                continue;
            }
            $legs[] = [
                'segment_type' => $segment->segmentType,
                'origin' => $segment->origin,
                'destination' => $segment->destination,
                'depart_dt' => $segment->departDt,
                'arrive_dt' => $segment->arriveDt,
            ];
        }
        return $this->staysFromLegArrays($legs);
    }

    /**
     * Display destination for a trip: first overnight/open-ended city.
     *
     * @param list<array<string, mixed>> $legs
     */
    public function firstStayDestination(array $legs): ?string
    {
        $stays = $this->staysFromLegArrays($legs);
        if ($stays === []) {
            return null;
        }
        return $stays[0]['destination'];
    }

    /**
     * @param list<array{
     *   origin: string,
     *   destination: string,
     *   depart_dt: string,
     *   arrive_dt: ?string
     * }> $legs
     * @return list<array{
     *   origin: string,
     *   destination: string,
     *   depart_dt: string,
     *   arrive_dt: ?string
     * }>
     */
    private function staysFromNormalized(array $legs): array
    {
        if ($legs === []) {
            return [];
        }

        $firstOrigin = $legs[0]['origin'];
        $stays = [];
        $count = count($legs);
        for ($i = 0; $i < $count; $i++) {
            $leg = $legs[$i];
            $next = $legs[$i + 1] ?? null;
            if ($this->isStayArrival($leg, $next, $firstOrigin)) {
                $stays[] = $leg;
            }
        }

        return $stays;
    }

    /**
     * @param array{
     *   origin: string,
     *   destination: string,
     *   depart_dt: string,
     *   arrive_dt: ?string
     * } $leg
     * @param array{
     *   origin: string,
     *   destination: string,
     *   depart_dt: string,
     *   arrive_dt: ?string
     * }|null $next
     */
    private function isStayArrival(array $leg, ?array $next, string $firstOrigin): bool
    {
        if ($next === null) {
            return $leg['destination'] !== $firstOrigin;
        }

        if ($leg['arrive_dt'] === null) {
            return false;
        }

        $arrive = $this->instant($leg['destination'], $leg['arrive_dt']);
        $depart = $this->instant($next['origin'], $next['depart_dt']);
        if ($depart <= $arrive) {
            return false;
        }

        return ($depart->getTimestamp() - $arrive->getTimestamp()) > self::MIN_GAP_SECONDS;
    }

    private function instant(string $airportCode, string $naiveDt): \DateTimeImmutable
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
}
