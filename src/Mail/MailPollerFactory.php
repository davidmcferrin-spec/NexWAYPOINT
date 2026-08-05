<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Receipts\EmailReceiptPdfBuilder;
use NexWaypoint\Receipts\ExpenseReceiptRepository;
use NexWaypoint\Receipts\ReceiptCaptureService;
use NexWaypoint\Receipts\ReceiptFileStore;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Users\UserRepository;

/**
 * Shared MailPoller construction for cron and admin re-parse.
 */
final class MailPollerFactory
{
    /**
     * @param array{db: \NexWaypoint\Core\Database, logger: Logger} $app
     */
    public static function create(array $app, MailSourceInterface $source, string $sourceName): MailPoller
    {
        $db = $app['db'];
        $logger = $app['logger'];
        $propertyRepo = new HotelPropertyRepository($db, $logger);
        $stayRepo = new HotelStayRepository($db, $logger, $propertyRepo);
        $tripRepo = new TripRepository($db, $logger);
        $rawRetention = max(1, (int) Env::get('MAIL_RAW_RETENTION_DAYS', '7'));
        $rawDir = NEXWAYPOINT_ROOT . '/storage/mail_raw';

        $receipts = null;
        if ($db->tableExists('expense_receipts')) {
            $fileStore = new ReceiptFileStore(NEXWAYPOINT_ROOT . '/storage/receipts', $logger);
            $receipts = new ReceiptCaptureService(
                new ExpenseReceiptRepository($db, $logger),
                $fileStore,
                new EmailReceiptPdfBuilder(),
                $tripRepo,
                $stayRepo,
                $propertyRepo,
                $logger,
                ReceiptCaptureService::retentionDaysFromEnv(),
            );
        }

        return new MailPoller(
            $source,
            $sourceName,
            new EmailConfirmationDetector(),
            new UserRepository($db, $logger),
            $propertyRepo,
            $stayRepo,
            $tripRepo,
            new CarrierRepository($db, $logger),
            new NotificationRepository($db),
            new ParseLogRepository($db),
            $logger,
            new RawMailStore($rawDir, $rawRetention, $logger),
            $receipts,
        );
    }

    public static function createSource(Logger $logger, ?string $sourceName = null): MailSourceInterface
    {
        $sourceName ??= Env::get('MAIL_SOURCE', 'dreamhost_imap');

        return match ($sourceName) {
            'dreamhost_imap' => new DreamHostImapSource($logger),
            'gmail' => new GmailApiSource(),
            'm365' => new M365GraphSource(),
            'null' => new NullMailSource(),
            default => throw new \RuntimeException(
                "Unknown MAIL_SOURCE '{$sourceName}'. Use dreamhost_imap, gmail, or m365."
            ),
        };
    }
}
