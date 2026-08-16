<?php

declare(strict_types=1);

/**
 * Run every 5-10 minutes via cron (or manually for testing):
 *   php-cli /path/to/NexWAYPOINT/cron/poll_mail.php
 *
 * Also invocable directly for manual testing -- see README "How to run the
 * poller manually for testing".
 */

use NexWaypoint\Core\CronRunRepository;
use NexWaypoint\Core\Env;
use NexWaypoint\Mail\MailPollerFactory;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\TripAutoCompleter;
use NexWaypoint\Trips\TripRepository;

$app = require dirname(__DIR__) . '/config/bootstrap.php';
/** @var \NexWaypoint\Core\Logger $logger */
$logger = $app['logger'];
$db = $app['db'];

$runs = $db->tableExists('cron_job_runs') ? new CronRunRepository($db) : null;
$runId = $runs?->begin(CronRunRepository::JOB_POLL_MAIL);

$exitCode = 0;

try {
    $sourceName = Env::get('MAIL_SOURCE', 'dreamhost_imap');
    $source = MailPollerFactory::createSource($logger, $sourceName);
    $poller = MailPollerFactory::create($app, $source, $sourceName);

    $result = $poller->run();

    try {
        (new TripAutoCompleter(
            new TripRepository($db, $logger),
            $logger,
            new AirportRepository($db, $logger),
        ))->completeDue();
    } catch (\Throwable $e) {
        $logger->warning('Trip auto-complete after mail poll failed', ['error' => $e->getMessage()]);
    }

    $fetched = (int) ($result['fetched'] ?? 0);
    $success = (int) ($result['success'] ?? 0);
    $failed = (int) ($result['failed'] ?? 0);
    /** @var list<string> $failureReasons */
    $failureReasons = is_array($result['failure_reasons'] ?? null) ? $result['failure_reasons'] : [];
    $errorMessage = null;
    if ($failureReasons !== []) {
        // Keep short for cron_job_runs.error_message (VARCHAR 500).
        $errorMessage = mb_substr(implode('; ', array_slice($failureReasons, 0, 3)), 0, 500);
    }

    if ($failed > 0 && $success === 0 && $fetched > 0) {
        $status = CronRunRepository::STATUS_FAILED;
        $exitCode = 1;
    } elseif ($failed > 0) {
        $status = CronRunRepository::STATUS_WARNING;
    } else {
        $status = CronRunRepository::STATUS_OK;
    }

    if ($runId !== null && $runs !== null) {
        $runs->finish($runId, $status, [
            'fetched' => $fetched,
            'success' => $success,
            'failed' => $failed,
            'source' => preg_match('/^[a-z0-9_]{1,40}$/', $sourceName) ? $sourceName : null,
        ], null, $errorMessage);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("Fetched: %d, Success: %d, Failed: %d\n", $fetched, $success, $failed));
        foreach ($failureReasons as $reason) {
            fwrite(STDERR, '  fail: ' . $reason . "\n");
        }
    }
} catch (\Throwable $e) {
    $logger->error('Mail poll job aborted', ['error' => $e->getMessage()]);
    if ($runId !== null && $runs !== null) {
        $runs->finish($runId, CronRunRepository::STATUS_FAILED, [], $e::class, $e->getMessage());
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Mail poll aborted: ' . $e->getMessage() . "\n");
    }
    $exitCode = 1;
}

exit($exitCode);
