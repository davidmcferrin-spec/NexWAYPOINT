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
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Mail\DreamHostImapSource;
use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\GmailApiSource;
use NexWaypoint\Mail\M365GraphSource;
use NexWaypoint\Mail\MailPoller;
use NexWaypoint\Mail\MailSourceInterface;
use NexWaypoint\Mail\ParseLogRepository;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__) . '/config/bootstrap.php';
/** @var \NexWaypoint\Core\Logger $logger */
$logger = $app['logger'];
$db = $app['db'];

$runs = $db->tableExists('cron_job_runs') ? new CronRunRepository($db) : null;
$runId = $runs?->begin(CronRunRepository::JOB_POLL_MAIL);

$exitCode = 0;

try {
    $sourceName = Env::get('MAIL_SOURCE', 'dreamhost_imap');

    $source = match ($sourceName) {
        'dreamhost_imap' => new DreamHostImapSource($logger),
        'gmail' => new GmailApiSource(),
        'm365' => new M365GraphSource(),
        default => throw new \RuntimeException("Unknown MAIL_SOURCE '{$sourceName}'. Use dreamhost_imap, gmail, or m365."),
    };

    /** @var MailSourceInterface $source */
    $propertyRepo = new HotelPropertyRepository($db, $logger);
    $poller = new MailPoller(
        $source,
        $sourceName,
        new EmailConfirmationDetector(),
        new UserRepository($db, $logger),
        $propertyRepo,
        new HotelStayRepository($db, $logger, $propertyRepo),
        new TripRepository($db, $logger),
        new CarrierRepository($db, $logger),
        new NotificationRepository($db),
        new ParseLogRepository($db),
        $logger,
    );

    $result = $poller->run();

    $fetched = (int) ($result['fetched'] ?? 0);
    $success = (int) ($result['success'] ?? 0);
    $failed = (int) ($result['failed'] ?? 0);

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
        ]);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("Fetched: %d, Success: %d, Failed: %d\n", $fetched, $success, $failed));
    }
} catch (\Throwable $e) {
    $logger->error('Mail poll job aborted', ['error' => $e->getMessage()]);
    if ($runId !== null && $runs !== null) {
        $runs->finish($runId, CronRunRepository::STATUS_FAILED, [], $e::class);
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Mail poll aborted: ' . $e->getMessage() . "\n");
    }
    $exitCode = 1;
}

exit($exitCode);
