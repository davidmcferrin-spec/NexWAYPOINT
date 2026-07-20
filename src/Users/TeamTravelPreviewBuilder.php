<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;

/**
 * Builds a visibility-filtered 21-day travel preview for the team board modal,
 * including multi-leg itineraries with layover gaps.
 */
final class TeamTravelPreviewBuilder
{
    private const TRANSIT_TYPES = ['flight', 'train', 'car'];

    public function __construct(
        private readonly TripRepository $trips,
        private readonly VisibilityEngine $visibility,
        private readonly VisibilityBlockRepository $blocks,
    ) {
    }

    /**
     * @return list<array{
     *   destination: string|null,
     *   dates: string|null,
     *   purpose: string|null,
     *   notes: string|null,
     *   itinerary: list<array{type: string, label: string}>,
     *   flights: list<array{label: string}>,
     *   redacted: bool
     * }>
     */
    public function build(int $viewerId, int $subjectId, int $daysAhead = 21): array
    {
        $out = [];
        $isSelf = $viewerId === $subjectId;

        foreach ($this->trips->findActiveOrUpcoming($subjectId, $daysAhead) as $trip) {
            if ($trip->id === null) {
                continue;
            }

            if (!$isSelf) {
                if ($trip->isPrivate) {
                    continue;
                }
                $hidden = $this->blocks->isHiddenFromViewer(
                    $subjectId,
                    $viewerId,
                    $trip->isPrivate,
                    VisibilityBlockRepository::TYPE_TRIP,
                    $trip->id,
                );
                if ($hidden) {
                    continue;
                }
            }

            $fields = $this->visibility->getVisibleFields(
                $viewerId,
                $subjectId,
                $isSelf ? false : $trip->isPrivate,
            )['visible_fields'];

            $canCity = $isSelf || in_array('destination_city', $fields, true);
            $canDates = $isSelf || in_array('travel_dates', $fields, true);
            $canPurpose = $isSelf || in_array('trip_purpose', $fields, true);
            $canNotes = $isSelf || in_array('notes', $fields, true);
            $canFlight = $isSelf || in_array('flight_number', $fields, true);
            $canCarrier = $isSelf || in_array('carrier', $fields, true);
            $canHotelName = $isSelf || in_array('hotel_name', $fields, true);

            $itinerary = $this->buildItinerary(
                $this->trips->segmentsForTrip($trip->id),
                $canCity,
                $canDates,
                $canFlight,
                $canCarrier,
                $canHotelName,
            );

            if (
                !$canCity && !$canDates && !$canPurpose && !$canNotes
                && $itinerary === []
            ) {
                continue;
            }

            $destination = $canCity ? trim($trip->destinationCity) : null;
            if ($destination === '') {
                $destination = null;
            }

            $dates = null;
            if ($canDates) {
                $dates = TeamLocationResolver::formatTripDateRange($trip->startDate, $trip->endDate);
            }

            // Backward-compatible flat flight list (leg items only).
            $flights = [];
            foreach ($itinerary as $item) {
                if ($item['type'] === 'leg') {
                    $flights[] = ['label' => $item['label']];
                }
            }

            $out[] = [
                'destination' => $destination,
                'dates' => $dates,
                'purpose' => $canPurpose ? $trip->tripPurpose : null,
                'notes' => $canNotes ? $trip->notes : null,
                'itinerary' => $itinerary,
                'flights' => $flights,
                'redacted' => !$canCity,
            ];
        }

        return $out;
    }

    /**
     * @param TripSegment[] $segments
     * @return list<array{type: string, label: string}>
     */
    private function buildItinerary(
        array $segments,
        bool $canCity,
        bool $canDates,
        bool $canFlight,
        bool $canCarrier,
        bool $canHotelName,
    ): array {
        $active = [];
        foreach ($segments as $segment) {
            if ($segment->status === 'cancelled') {
                continue;
            }
            $active[] = $segment;
        }

        $items = [];
        $prevTransit = null;

        foreach ($active as $segment) {
            $type = $segment->segmentType;

            if (in_array($type, self::TRANSIT_TYPES, true)) {
                if ($prevTransit !== null) {
                    $layover = $this->layoverItem($prevTransit, $segment, $canCity, $canDates);
                    if ($layover !== null) {
                        $items[] = $layover;
                    }
                }

                $leg = $this->legItem($segment, $canCity, $canDates, $canFlight, $canCarrier);
                if ($leg !== null) {
                    $items[] = $leg;
                    $prevTransit = $segment;
                }
                continue;
            }

            if ($type === 'hotel') {
                $hotel = $this->hotelItem($segment, $canCity, $canDates, $canHotelName);
                if ($hotel !== null) {
                    $items[] = $hotel;
                }
                // Hotel breaks connection chain for layover detection.
                $prevTransit = null;
            }
        }

        return $items;
    }

    /**
     * @return array{type: string, label: string}|null
     */
    private function legItem(
        TripSegment $segment,
        bool $canCity,
        bool $canDates,
        bool $canFlight,
        bool $canCarrier,
    ): ?array {
        $bits = [];
        $kind = match ($segment->segmentType) {
            'train' => 'Train',
            'car' => 'Ground',
            default => 'Flight',
        };

        if ($canCarrier && $segment->carrier !== null && $segment->carrier !== '') {
            $bits[] = $segment->carrier;
        }
        if ($canFlight && $segment->flightNumber !== null && $segment->flightNumber !== '') {
            $bits[] = $segment->flightNumber;
        }

        $route = '';
        if ($canCity) {
            $origin = $segment->origin ?? '?';
            $dest = $segment->destination ?? '?';
            $route = $origin . ' → ' . $dest;
        }

        $label = trim(implode(' ', $bits));
        if ($route !== '') {
            $label = $label !== '' ? $label . ' · ' . $route : $route;
        }
        if ($label === '') {
            if (!$canFlight && !$canCarrier && !$canCity && !$canDates) {
                return null;
            }
            $label = $kind;
        }

        if ($canDates && $segment->departDt !== null) {
            $when = $this->formatDateTimeShort($segment->departDt);
            if ($when !== null) {
                $label .= ' · ' . $when;
            }
        }

        return ['type' => 'leg', 'label' => $label];
    }

    /**
     * @return array{type: string, label: string}|null
     */
    private function layoverItem(
        TripSegment $current,
        TripSegment $next,
        bool $canCity,
        bool $canDates,
    ): ?array {
        if ($current->arriveDt === null || $next->departDt === null) {
            return null;
        }

        try {
            $arrive = new \DateTimeImmutable($current->arriveDt);
            $depart = new \DateTimeImmutable($next->departDt);
        } catch (\Exception) {
            return null;
        }

        if ($depart <= $arrive) {
            return null;
        }

        $label = 'Layover';
        if ($canCity) {
            $city = trim((string) ($current->destination ?? ''));
            if ($city !== '') {
                $label = 'Layover in ' . $city;
            }
        }

        if ($canDates) {
            $duration = $this->formatDuration($arrive, $depart);
            if ($duration !== null) {
                $label .= ' · ' . $duration;
            }
        }

        return ['type' => 'layover', 'label' => $label];
    }

    /**
     * @return array{type: string, label: string}|null
     */
    private function hotelItem(
        TripSegment $segment,
        bool $canCity,
        bool $canDates,
        bool $canHotelName,
    ): ?array {
        $name = null;
        if ($canHotelName) {
            // Hotel segments typically stash property/city in destination; carrier may hold brand.
            $name = trim((string) ($segment->destination ?? ''));
            if ($name === '' && $segment->carrier !== null) {
                $name = trim($segment->carrier);
            }
            if ($name === '') {
                $name = null;
            }
        }

        if ($name === null && !$canDates && !$canCity) {
            return null;
        }

        $label = $name !== null ? $name : 'Hotel stay';
        if ($canDates && $segment->departDt !== null && $segment->arriveDt !== null) {
            try {
                $checkIn = (new \DateTimeImmutable($segment->departDt))->format('Y-m-d');
                $checkOut = (new \DateTimeImmutable($segment->arriveDt))->format('Y-m-d');
                $label .= ' · ' . TeamLocationResolver::formatTripDateRange($checkIn, $checkOut);
            } catch (\Exception) {
                // Keep label without dates.
            }
        } elseif ($canCity && $name === null && $segment->destination !== null) {
            $label = 'Hotel · ' . $segment->destination;
        }

        return ['type' => 'hotel', 'label' => $label];
    }

    private function formatDateTimeShort(string $dt): ?string
    {
        try {
            return (new \DateTimeImmutable($dt))->format('M j g:ia');
        } catch (\Exception) {
            return null;
        }
    }

    private function formatDuration(\DateTimeImmutable $start, \DateTimeImmutable $end): ?string
    {
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0) {
            return null;
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0 && $minutes > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }
        if ($hours > 0) {
            return $hours . 'h';
        }
        return $minutes . 'm';
    }
}
