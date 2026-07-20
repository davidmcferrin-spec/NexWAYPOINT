<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;

/**
 * Builds a visibility-filtered 21-day travel preview for the team board modal.
 */
final class TeamTravelPreviewBuilder
{
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

            if (!$canCity && !$canDates && !$canPurpose && !$canNotes && !$canFlight && !$canCarrier) {
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

            $flights = [];
            if ($canFlight || $canCarrier) {
                foreach ($this->trips->segmentsForTrip($trip->id) as $segment) {
                    if ($segment->segmentType !== 'flight') {
                        continue;
                    }
                    $bits = [];
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
                        $label = 'Flight';
                    }
                    $flights[] = ['label' => $label];
                }
            }

            $out[] = [
                'destination' => $destination,
                'dates' => $dates,
                'purpose' => $canPurpose ? $trip->tripPurpose : null,
                'notes' => $canNotes ? $trip->notes : null,
                'flights' => $flights,
                'redacted' => !$canCity,
            ];
        }

        return $out;
    }
}
