<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Logger;

/**
 * Compares the flight_status row before/after a FlightAware enrichment call
 * and writes notifications for the deltas that matter to a traveler:
 * departure delay > 30 min, gate change, cancellation, and landing.
 *
 * Email/push delivery is a documented future phase -- this v1 only writes
 * to the notifications table, which the dashboard polls.
 */
final class AlertEvaluator
{
    private const DELAY_THRESHOLD_MINUTES = 30;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before flight_status row prior to this enrichment call (or null if new)
     * @param array<string, mixed> $after fields just written by FlightAwareClient::enrichSegment()
     */
    public function evaluate(int $userId, TripSegment $segment, ?array $before, array $after): void
    {
        $route = trim(($segment->origin ?? '?') . ' -> ' . ($segment->destination ?? '?'));

        if (($after['status'] ?? null) === 'Cancelled' && ($before['status'] ?? null) !== 'Cancelled') {
            $this->fire($userId, $segment, 'cancellation', "Flight cancelled: {$route}");
            return;
        }

        if (($after['status'] ?? null) === 'Diverted' && ($before['status'] ?? null) !== 'Diverted') {
            $this->fire($userId, $segment, 'diversion', "Flight diverted: {$route}");
            return;
        }

        $delayMinutes = (int) ($after['delay_minutes'] ?? 0);
        $priorDelay = (int) ($before['delay_minutes'] ?? 0);
        if ($delayMinutes >= self::DELAY_THRESHOLD_MINUTES && $priorDelay < self::DELAY_THRESHOLD_MINUTES) {
            $this->fire($userId, $segment, 'delay', "{$route} delayed {$delayMinutes} minutes");
        }

        $beforeGate = $before['gate'] ?? null;
        $afterGate = $after['gate'] ?? null;
        if ($afterGate !== null && $beforeGate !== null && $afterGate !== $beforeGate) {
            $this->fire($userId, $segment, 'gate_change', "Gate change for {$route}: {$beforeGate} -> {$afterGate}");
        }

        if (($after['status'] ?? null) === 'Landed' && ($before['status'] ?? null) !== 'Landed') {
            $this->fire($userId, $segment, 'landed', "Landed: {$route}");
        }
    }

    private function fire(int $userId, TripSegment $segment, string $alertType, string $message): void
    {
        $this->notifications->create($userId, $segment->id, $alertType, $message);
        $this->logger->info('Alert fired', ['user_id' => $userId, 'segment_id' => $segment->id, 'type' => $alertType, 'message' => $message]);
    }
}
