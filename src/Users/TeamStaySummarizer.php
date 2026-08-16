<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\ItineraryStayPlanner;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripSegment;

/**
 * Overnight stay cities with weekday from/to labels for the team board.
 *
 * Last dest ≠ first origin is open-ended (no return booked).
 */
final class TeamStaySummarizer
{
    public const WEEK_DAYS = 7;

    /** Sunday of the calendar week containing $day (US week). */
    public static function sundayOfWeek(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $day = $day->setTime(0, 0, 0);
        $dow = (int) $day->format('w');
        return $dow === 0 ? $day : $day->modify('-' . $dow . ' days');
    }

    public function __construct(
        private readonly ?AirportRepository $airports = null,
    ) {
    }

    /**
     * @param TripSegment[] $segments
     * @return list<array{
     *   city: string,
     *   start: string,
     *   end: string|null,
     *   start_label: string,
     *   end_label: string|null,
     *   open_ended: bool,
     *   dates: string
     * }>
     */
    public function staysForTrip(Trip $trip, array $segments): array
    {
        $planner = new ItineraryStayPlanner($this->airports);
        $raw = $planner->staysFromSegments($segments);
        if ($raw === []) {
            return $this->fallbackFromTripDates($trip);
        }

        $out = [];
        $count = count($raw);
        for ($i = 0; $i < $count; $i++) {
            $stay = $raw[$i];
            $next = $raw[$i + 1] ?? null;
            $startDt = $this->dayFromNaive($stay['arrive_dt'] ?? $stay['depart_dt']);
            if ($startDt === null) {
                continue;
            }

            $endDt = null;
            $openEnded = false;
            if ($next !== null) {
                $endDt = $this->dayFromNaive($next['depart_dt']);
            } else {
                $endDt = $this->nextTransitDepartAfter($segments, $stay);
                $openEnded = $endDt === null;
            }

            $city = $this->cityLabel($stay['destination'], $trip);
            if ($city === '') {
                continue;
            }

            $out[] = $this->row($city, $startDt, $endDt, $openEnded);
        }

        return $out;
    }

    /**
     * Stay cities overlapping the next $days days, joined with → .
     *
     * @param list<array{city: string, start: string, end: string|null, open_ended: bool}> $stays
     */
    public function weekCities(array $stays, ?\DateTimeImmutable $now = null, int $days = self::WEEK_DAYS): ?string
    {
        $now ??= new \DateTimeImmutable('today');
        $windowStart = $now->setTime(0, 0, 0);
        $windowEnd = $windowStart->modify('+' . $days . ' days');

        $cities = [];
        foreach ($stays as $stay) {
            $city = trim((string) ($stay['city'] ?? ''));
            if ($city === '') {
                continue;
            }
            $start = $this->dayFromNaive((string) $stay['start']);
            if ($start === null) {
                continue;
            }
            $open = !empty($stay['open_ended']);
            $end = isset($stay['end']) && is_string($stay['end']) && $stay['end'] !== ''
                ? $this->dayFromNaive($stay['end'])
                : null;
            if ($end === null && !$open) {
                $end = $start;
            }

            $stayEndExclusive = $open
                ? $windowEnd
                : ($end ?? $start)->modify('+1 day');
            if ($stayEndExclusive <= $windowStart || $start >= $windowEnd) {
                continue;
            }
            if (!in_array($city, $cities, true)) {
                $cities[] = $city;
            }
        }

        if ($cities === []) {
            return null;
        }
        return implode(' → ', $cities);
    }

    /**
     * City covering a calendar day, or null (treat as Home on the board).
     * When two stays overlap a travel day, the later start wins.
     *
     * @param list<array{city: string, start: string, end: string|null, open_ended: bool}> $stays
     */
    public function cityOnDate(array $stays, \DateTimeImmutable $day): ?string
    {
        $dayKey = $day->format('Y-m-d');
        $hit = null;
        $hitStart = null;
        foreach ($stays as $stay) {
            $city = trim((string) ($stay['city'] ?? ''));
            $start = (string) ($stay['start'] ?? '');
            if ($city === '' || $start === '') {
                continue;
            }
            $open = !empty($stay['open_ended']);
            $end = isset($stay['end']) && is_string($stay['end']) && $stay['end'] !== ''
                ? $stay['end']
                : ($open ? '9999-12-31' : $start);
            if ($dayKey < $start || $dayKey > $end) {
                continue;
            }
            if ($hitStart === null || $start >= $hitStart) {
                $hit = $city;
                $hitStart = $start;
            }
        }
        return $hit;
    }

    /**
     * @param list<array{city?: string|null, start?: string|null, end?: string|null, open_ended?: bool}> $stays
     * @return list<string|null>
     */
    public function ganttCells(array $stays, \DateTimeImmutable $from, int $days): array
    {
        $cells = [];
        for ($i = 0; $i < $days; $i++) {
            $cells[] = $this->cityOnDate($stays, $from->modify('+' . $i . ' days'));
        }
        return $cells;
    }

    public static function weekdayDate(\DateTimeImmutable $day): string
    {
        return $day->format('D M j');
    }

    /**
     * @return list<array{city: string, start: string, end: string|null, start_label: string, end_label: string|null, open_ended: bool, dates: string}>
     */
    private function fallbackFromTripDates(Trip $trip): array
    {
        $city = trim($trip->destinationCity);
        if ($city === '') {
            return [];
        }
        $start = $this->dayFromNaive($trip->startDate);
        $end = $this->dayFromNaive($trip->endDate);
        if ($start === null) {
            return [];
        }
        return [$this->row($city, $start, $end, false)];
    }

    /**
     * @return array{city: string, start: string, end: string|null, start_label: string, end_label: string|null, open_ended: bool, dates: string}
     */
    private function row(
        string $city,
        \DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
        bool $openEnded,
    ): array {
        $startLabel = self::weekdayDate($start);
        $endLabel = $openEnded ? null : ($end !== null ? self::weekdayDate($end) : $startLabel);
        $dates = $openEnded
            ? $startLabel . ' – open-ended'
            : ($endLabel !== null && $endLabel !== $startLabel
                ? $startLabel . ' – ' . $endLabel
                : $startLabel);

        return [
            'city' => $city,
            'start' => $start->format('Y-m-d'),
            'end' => $openEnded ? null : ($end !== null ? $end->format('Y-m-d') : $start->format('Y-m-d')),
            'start_label' => $startLabel,
            'end_label' => $endLabel,
            'open_ended' => $openEnded,
            'dates' => $dates,
        ];
    }

    /**
     * First transit after this stay’s inbound arrival (return home, etc.).
     *
     * @param TripSegment[] $segments
     * @param array{depart_dt: string, arrive_dt: ?string} $stay
     */
    private function nextTransitDepartAfter(array $segments, array $stay): ?\DateTimeImmutable
    {
        $after = $stay['arrive_dt'] ?? $stay['depart_dt'];
        if ($after === null || trim((string) $after) === '') {
            $after = $stay['depart_dt'];
        }
        $best = null;
        foreach ($segments as $segment) {
            if ($segment->status === 'cancelled') {
                continue;
            }
            if (!in_array($segment->segmentType, ItineraryStayPlanner::TRANSIT_TYPES, true)) {
                continue;
            }
            $depart = $segment->departDt;
            if ($depart === null || $depart === '') {
                continue;
            }
            if ($depart <= $after) {
                continue;
            }
            if ($best === null || $depart < $best) {
                $best = $depart;
            }
        }

        return $this->dayFromNaive($best);
    }

    private function cityLabel(string $code, Trip $trip): string
    {
        if ($this->airports !== null) {
            $city = $this->airports->cityFor($code);
            if ($city !== null && trim($city) !== '') {
                return trim($city);
            }
        }
        $code = trim($code);
        if ($code !== '') {
            return $code;
        }
        return trim($trip->destinationCity);
    }

    private function dayFromNaive(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($raw))->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }
    }
}
