<?php

declare(strict_types=1);

namespace NexWaypoint\Calendar;

/**
 * One VEVENT payload before ICS serialization.
 */
final class IcsEvent
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $summary,
        /** Timed start in UTC, or Y-m-d for all-day. */
        public readonly string $dtStart,
        /** Timed end in UTC, or exclusive end date Y-m-d for all-day. */
        public readonly string $dtEnd,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly bool $allDay = false,
        public readonly ?\DateTimeImmutable $lastModified = null,
        public readonly int $sequence = 0,
        public readonly array $categories = [],
        public readonly string $status = 'CONFIRMED',
    ) {
    }
}
