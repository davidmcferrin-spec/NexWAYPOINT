<?php

declare(strict_types=1);

/**
 * Run every 10 minutes via cron:
 *   php-cli /path/to/NexWAYPOINT/cron/enrich_flights.php
 *
 * Sweeps all non-terminal flight segments departing within 48 hours,
 * refreshes them from FlightAware (respecting the per-segment cache
 * window), and fires alerts for anything that changed materially.
 */

use NexWaypoint\Trips\AlertEvaluator;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\FlightAwareClient;
use NexWaypoint\Trips\FlightStatusRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;

$app = require dirname(__DIR__) . '/config/bootstrap.php';
/** @var \NexWaypoint\Core\Logger $logger */
$logger = $app['logger'];
$db = $app['db'];

$tripRepo = new TripRepository($db, $logger);
$carrierRepo = new CarrierRepository($db, $logger);
$flightStatusRepo = new FlightStatusRepository($db);
$notificationRepo = new NotificationRepository($db);
$alertEvaluator = new AlertEvaluator($notificationRepo, $logger);
$flightAware = new FlightAwareClient($logger, $flightStatusRepo);

$segments = $tripRepo->findSegmentsNeedingEnrichment(48);
$logger->info('Flight enrichment sweep starting', ['segment_count' => count($segments)]);

$enriched = 0;
$skipped = 0;
$failed = 0;

foreach ($segments as $segment) {
    if ($segment->flightNumber === null || $segment->flightNumber === '') {
        $logger->warning('Segment has no flight_number, cannot enrich', ['segment_id' => $segment->id]);
        $skipped++;
        continue;
    }

    $ident = $segment->flightNumber;
    if ($segment->carrierId !== null) {
        $carrier = $carrierRepo->find($segment->carrierId);
        if ($carrier !== null) {
            $built = $carrier->flightIdent($segment->flightNumber);
            if ($built !== null) {
                $ident = $built;
            }
        }
    }

    try {
        $before = $segment->id !== null ? $flightStatusRepo->findBySegment($segment->id) : null;
        $after = $flightAware->enrichSegment($segment, $ident);

        if ($after === null) {
            $skipped++;
            continue;
        }

        $ownerRow = $db->fetchOne('SELECT owner_id FROM trips WHERE id = :id', ['id' => $segment->tripId]);
        if ($ownerRow !== null) {
            $alertEvaluator->evaluate((int) $ownerRow['owner_id'], $segment, $before, $after);
        }

        if (isset($after['status']) && $after['status'] === 'Landed' && $segment->id !== null) {
            $tripRepo->updateSegmentStatus($segment->id, 'landed');
        }

        $enriched++;
    } catch (\Throwable $e) {
        $logger->error('Enrichment failed for segment', ['segment_id' => $segment->id, 'error' => $e->getMessage()]);
        $failed++;
    }
}

$logger->info('Flight enrichment sweep complete', ['enriched' => $enriched, 'skipped' => $skipped, 'failed' => $failed]);

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "Enriched: {$enriched}, Skipped: {$skipped}, Failed: {$failed}\n");
}

exit($failed > 0 ? 1 : 0);
