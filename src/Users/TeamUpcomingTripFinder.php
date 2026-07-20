<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;

/**
 * Finds the soonest upcoming trip destination a viewer may see on the
 * team board (next N days). Pass $excludeTripId to skip the trip the
 * subject is already on so "Next" is a later destination.
 * Subject viewing self may see their own private trips.
 */
final class TeamUpcomingTripFinder
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly VisibilityEngine $visibility,
        private readonly VisibilityBlockRepository $blocks,
    ) {
    }

    public function findVisible(
        int $viewerId,
        int $subjectId,
        int $daysAhead = 21,
        ?int $excludeTripId = null,
    ): ?Trip {
        foreach ($this->trips->findActiveOrUpcoming($subjectId, $daysAhead) as $trip) {
            if ($excludeTripId !== null && (int) $trip->id === $excludeTripId) {
                continue;
            }
            $isSelf = $viewerId === $subjectId;
            if (!$isSelf) {
                if ($trip->isPrivate) {
                    continue;
                }
                $hidden = $this->blocks->isHiddenFromViewer(
                    $subjectId,
                    $viewerId,
                    $trip->isPrivate,
                    VisibilityBlockRepository::TYPE_TRIP,
                    (int) $trip->id,
                );
                if ($hidden) {
                    continue;
                }
                $fields = $this->visibility->getVisibleFields($viewerId, $subjectId, false);
                if (!in_array('destination_city', $fields['visible_fields'], true)) {
                    continue;
                }
            }
            if (trim($trip->destinationCity) === '') {
                continue;
            }
            return $trip;
        }
        return null;
    }
}
