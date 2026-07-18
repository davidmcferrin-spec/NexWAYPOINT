<?php

declare(strict_types=1);

/**
 * Run every 5-10 minutes via cron (or manually for testing):
 *   php-cli /path/to/NexWAYPONT/cron/poll_mail.php
 *
 * Also invocable directly for manual testing -- see README "How to run the
 * poller manually for testing".
 */

use NexWaypont\Core\Env;
use NexWaypont\Hotels\HotelStayRepository;
use NexWaypont\Mail\DreamHostImapSource;
use NexWaypont\Mail\EmailConfirmationDetector;
use NexWaypont\Mail\GmailApiSource;
use NexWaypont\Mail\M365GraphSource;
use NexWaypont\Mail\MailPoller;
use NexWaypont\Mail\MailSourceInterface;
use NexWaypont\Mail\ParseLogRepository;
use NexWaypont\Trips\NotificationRepository;
use NexWaypont\Users\UserRepository;

$app = require dirname(__DIR__) . '/config/bootstrap.php';
/** @var \NexWaypont\Core\Logger $logger */
$logger = $app['logger'];
$db = $app['db'];

$sourceName = Env::get('MAIL_SOURCE', 'dreamhost_imap');

$source = match ($sourceName) {
    'dreamhost_imap' => new DreamHostImapSource($logger),
    'gmail' => new GmailApiSource(),
    'm365' => new M365GraphSource(),
    default => throw new \RuntimeException("Unknown MAIL_SOURCE '{$sourceName}'. Use dreamhost_imap, gmail, or m365."),
};

/** @var MailSourceInterface $source */
$poller = new MailPoller(
    $source,
    $sourceName,
    new EmailConfirmationDetector(),
    new UserRepository($db, $logger),
    new HotelStayRepository($db, $logger),
    new NotificationRepository($db),
    new ParseLogRepository($db),
    $logger,
);

$result = $poller->run();

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, sprintf("Fetched: %d, Success: %d, Failed: %d\n", $result['fetched'], $result['success'], $result['failed']));
}

exit($result['failed'] > 0 && $result['success'] === 0 && $result['fetched'] > 0 ? 1 : 0);
