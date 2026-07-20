<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;
use NexWaypoint\Hotels\HotelProperty;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Mail\Parsers\GenericHotelConfirmationParser;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Users\UserRepository;

/**
 * Orchestrates one polling pass: fetch unseen mail -> detect type -> match
 * owner by From: address -> parse -> either create a draft record + notify
 * the owner, or route to the PARSE_FAILED queue for manual review.
 *
 * v1 parser coverage: hotel confirmations only (GenericHotelConfirmationParser).
 * Flight/train/car parsers are a documented next step (README roadmap) --
 * MailPoller already routes those types to the review queue with a clear
 * "no parser for this type yet" reason rather than silently dropping them.
 */
final class MailPoller
{
    private const MIN_CONFIDENCE_DEFAULT = 0.75;

    public function __construct(
        private readonly MailSourceInterface $source,
        private readonly string $sourceName,
        private readonly EmailConfirmationDetector $detector,
        private readonly UserRepository $users,
        private readonly HotelPropertyRepository $hotelProperties,
        private readonly HotelStayRepository $hotelStays,
        private readonly NotificationRepository $notifications,
        private readonly ParseLogRepository $parseLog,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{fetched: int, success: int, failed: int}
     */
    public function run(): array
    {
        $minConfidence = (float) (Env::get('MAIL_MIN_PARSE_CONFIDENCE', (string) self::MIN_CONFIDENCE_DEFAULT));

        $messages = $this->source->fetchUnseenMessages();
        $success = 0;
        $failed = 0;

        foreach ($messages as $message) {
            try {
                $outcome = $this->processOne($message, $minConfidence);
                if ($outcome) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Unhandled error processing message', ['uid' => $message->uid, 'error' => $e->getMessage()]);
                $this->source->markFailed($message->uid, 'Unhandled exception: ' . $e->getMessage());
                $this->parseLog->record(
                    $message->receivedAt,
                    $message->fromAddress,
                    $message->subject,
                    $message->uid,
                    $this->sourceName,
                    null,
                    'failed',
                    'Unhandled exception during processing',
                    null,
                    null,
                    null,
                );
                $failed++;
            }
        }

        $this->source->disconnect();

        $this->logger->info('Mail poll complete', ['fetched' => count($messages), 'success' => $success, 'failed' => $failed]);
        return ['fetched' => count($messages), 'success' => $success, 'failed' => $failed];
    }

    private function processOne(EmailMessage $message, float $minConfidence): bool
    {
        if ($this->parseLog->alreadyProcessed($message->uid, $this->sourceName)) {
            $this->logger->info('Skipping already-logged message', ['uid' => $message->uid]);
            $this->source->markProcessed($message->uid);
            return true;
        }

        $detection = $this->detector->detect($message);
        $owner = $this->users->findByEmail($message->fromAddress);

        if ($owner === null) {
            $this->fail($message, $detection['type'], 'No NexWAYPOINT user matches From: address ' . $message->fromAddress);
            return false;
        }

        if ($detection['type'] !== 'hotel') {
            $reason = $detection['type'] === 'unknown'
                ? 'Unrecognized sender/subject pattern'
                : "No parser implemented yet for type '{$detection['type']}' (see README roadmap)";
            $this->fail($message, $detection['type'], $reason, $owner->id);
            return false;
        }

        $parser = new GenericHotelConfirmationParser();
        $extracted = $parser->parse($message);
        $confidence = $parser->confidenceScore();

        if ($extracted === null || $confidence < $minConfidence) {
            $reason = $extracted === null
                ? 'Parser found no confirmation code or property name'
                : sprintf('Parse confidence %.2f below threshold %.2f', $confidence, $minConfidence);
            $this->fail($message, 'hotel', $reason, $owner->id, $confidence);
            return false;
        }

        if ($extracted['check_in'] === null || $extracted['check_out'] === null || $extracted['property_name'] === null) {
            $this->fail($message, 'hotel', 'Missing required field (property_name/check_in/check_out) after parse', $owner->id, $confidence);
            return false;
        }

        $property = $this->hotelProperties->findByNameCity($owner->id, $extracted['property_name'], null);
        if ($property === null) {
            $property = $this->hotelProperties->create(new HotelProperty(
                id: null,
                userId: $owner->id,
                hotelName: $extracted['property_name'],
                brand: null,
                addressLine1: $extracted['address'],
                addressLine2: null,
                city: null,
                stateRegion: null,
                postalCode: null,
                country: null,
                latitude: null,
                longitude: null,
                hasDesk: false,
                deskNotes: null,
                hasPool: false,
                hasHotTub: false,
                hasBreakfast: false,
                breakfastNotes: null,
                hasGym: false,
                hasFreeParking: false,
                hasAirportShuttle: false,
                hasEvCharging: false,
                hasOnsiteRestaurant: false,
                hasOffsiteGym: false,
                walkToOffice: false,
                walkToOfficeNotes: null,
                wifiQuality: null,
                noiseLevel: null,
                uniqueFeatures: $extracted['room_type'],
                isBlacklisted: false,
                blacklistReason: null,
            ), $owner->id);
        }

        $stay = new HotelStay(
            id: null,
            userId: $owner->id,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: $extracted['check_in'],
            stayEnd: $extracted['check_out'],
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: 'email_import',
            confirmationCode: $extracted['confirmation_code'],
            wouldReturn: null,
            notes: 'Auto-imported from a forwarded confirmation email. Review and fill in amenities/rating.',
        );

        $created = $this->hotelStays->create($stay, $owner->id);

        $this->notifications->create(
            $owner->id,
            null,
            'hotel_import',
            "We found a hotel stay at {$property->hotelName} ({$created->stayStart} to {$created->stayEnd}). Review it in Hotel Stays."
        );

        $this->source->markProcessed($message->uid);
        $this->parseLog->record(
            $message->receivedAt,
            $message->fromAddress,
            $message->subject,
            $message->uid,
            $this->sourceName,
            'hotel',
            'success',
            null,
            $confidence,
            $owner->id,
            null,
        );

        $this->logger->info('Hotel confirmation imported', ['hotel_stay_id' => $created->id, 'user_id' => $owner->id]);
        return true;
    }

    private function fail(EmailMessage $message, string $detectedType, string $reason, ?int $matchedUserId = null, ?float $confidence = null): void
    {
        $this->source->markFailed($message->uid, $reason);
        $this->parseLog->record(
            $message->receivedAt,
            $message->fromAddress,
            $message->subject,
            $message->uid,
            $this->sourceName,
            $detectedType,
            'failed',
            $reason,
            $confidence,
            $matchedUserId,
            null,
        );
        $this->logger->warning('Message routed to review queue', ['uid' => $message->uid, 'reason' => $reason]);
    }
}
