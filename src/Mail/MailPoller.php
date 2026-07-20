<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Mail\Parsers\AmericanAirlinesParser;
use NexWaypoint\Mail\Parsers\AmtrakParser;
use NexWaypoint\Mail\Parsers\BreezeAirlinesParser;
use NexWaypoint\Mail\Parsers\DeltaAirlinesParser;
use NexWaypoint\Mail\Parsers\GenericHotelConfirmationParser;
use NexWaypoint\Mail\Parsers\HiltonHotelParser;
use NexWaypoint\Mail\Parsers\MarriottHotelParser;
use NexWaypoint\Mail\Parsers\UnitedAirlinesParser;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Users\UserRepository;

/**
 * Orchestrates one polling pass: fetch unseen mail -> normalize forwards ->
 * detect type/event -> match owner by outer From: -> parse -> upsert/cancel.
 *
 * Messages may be direct from the vendor or teammate forwards (Gmail /
 * Outlook / Proton / Yahoo / Apple). ForwardedMailNormalizer runs first so
 * detectors and brand parsers always see the underlying confirmation.
 * Events: confirm, change, cancel, ignore (and flight status). Upserts key
 * on confirmation/PNR so updates replace prior legs/stays.
 *
 * Supported: AA / Delta / United / Breeze flights, Amtrak, Hilton / Marriott /
 * generic hotel. Folio / bag-receipt / status mail is ignored where detected.
 */
final class MailPoller
{
    private const MIN_CONFIDENCE_DEFAULT = 0.75;

    /** @var array<string, string> */
    private const CARRIER_NAMES = [
        'AA' => 'American Airlines',
        'DL' => 'Delta Air Lines',
        'UA' => 'United Airlines',
        'MX' => 'Breeze Airways',
        '2V' => 'Amtrak',
    ];

    public function __construct(
        private readonly MailSourceInterface $source,
        private readonly string $sourceName,
        private readonly EmailConfirmationDetector $detector,
        private readonly UserRepository $users,
        private readonly HotelPropertyRepository $hotelProperties,
        private readonly HotelStayRepository $hotelStays,
        private readonly TripRepository $trips,
        private readonly CarrierRepository $carriers,
        private readonly NotificationRepository $notifications,
        private readonly ParseLogRepository $parseLog,
        private readonly Logger $logger,
    ) {
    }

    private ?string $lastFailureReason = null;

    /**
     * @return array{fetched: int, success: int, failed: int, failure_reasons: list<string>}
     */
    public function run(): array
    {
        $minConfidence = (float) (Env::get('MAIL_MIN_PARSE_CONFIDENCE', (string) self::MIN_CONFIDENCE_DEFAULT));

        $messages = $this->source->fetchUnseenMessages();
        $success = 0;
        $failed = 0;
        /** @var list<string> $failureReasons */
        $failureReasons = [];

        foreach ($messages as $message) {
            try {
                $outcome = $this->processOne($message, $minConfidence);
                if ($outcome) {
                    $success++;
                } else {
                    $failed++;
                    $reason = $this->lastFailureReason;
                    if ($reason !== null) {
                        $failureReasons[] = $reason;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Unhandled error processing message', ['uid' => $message->uid, 'error' => $e->getMessage()]);
                $reason = 'Unhandled exception: ' . $e->getMessage();
                $this->source->markFailed($message->uid, $reason);
                $this->parseLog->record(
                    $message->receivedAt,
                    $message->fromAddress,
                    $message->subject,
                    $message->uid,
                    $this->sourceName,
                    null,
                    'failed',
                    $reason,
                    null,
                    null,
                    null,
                );
                $failed++;
                $failureReasons[] = $reason;
            }
        }

        $this->source->disconnect();

        $this->logger->info('Mail poll complete', ['fetched' => count($messages), 'success' => $success, 'failed' => $failed]);
        return [
            'fetched' => count($messages),
            'success' => $success,
            'failed' => $failed,
            'failure_reasons' => $failureReasons,
        ];
    }

    private function processOne(EmailMessage $message, float $minConfidence): bool
    {
        $this->lastFailureReason = null;

        if ($this->parseLog->alreadyProcessed($message->uid, $this->sourceName)) {
            $this->logger->info('Skipping already-logged message', ['uid' => $message->uid]);
            $this->source->markProcessed($message->uid);
            return true;
        }

        // Outer From: still used for ownership; subject/body cleaned for vendor parsers.
        $normalized = ForwardedMailNormalizer::normalize($message);

        $detection = $this->detector->detect($normalized);
        $owner = $this->users->findByEmail($message->fromAddress);

        if ($owner === null) {
            $this->fail($message, $detection['type'], 'No NexWAYPOINT user matches From: address ' . $message->fromAddress);
            return false;
        }

        if (in_array($detection['event'], ['ignore', 'status'], true)) {
            return $this->ignore($message, $detection['type'], 'Detector marked event as ' . $detection['event'], $owner->id);
        }

        if ($detection['type'] === 'unknown') {
            $this->fail(
                $message,
                'unknown',
                'Unrecognized sender/subject pattern (subject: ' . mb_substr($message->subject, 0, 120) . ')',
                $owner->id
            );
            return false;
        }

        if ($detection['type'] === 'car') {
            $this->fail($message, 'car', "No parser implemented yet for type 'car' (see README roadmap)", $owner->id);
            return false;
        }

        $parser = $this->resolveParser($detection['type'], $detection['matched_domain'] ?? '');
        if ($parser === null) {
            $this->fail($message, $detection['type'], "No parser for type '{$detection['type']}'", $owner->id);
            return false;
        }

        $extracted = $parser->parse($normalized);
        $confidence = $parser->confidenceScore();
        $event = is_array($extracted) ? (string) ($extracted['event'] ?? $detection['event']) : $detection['event'];

        if (is_array($extracted) && $event === 'ignore') {
            return $this->ignore($message, $detection['type'], 'Parser marked message as ignore', $owner->id, $confidence);
        }

        if ($extracted === null || ($event !== 'cancel' && $confidence < $minConfidence)) {
            $reason = $extracted === null
                ? 'Parser could not extract a usable confirmation'
                : sprintf('Parse confidence %.2f below threshold %.2f', $confidence, $minConfidence);
            $this->fail($message, $detection['type'], $reason, $owner->id, $confidence);
            return false;
        }

        $kind = (string) ($extracted['kind'] ?? $detection['type']);

        if ($kind === 'hotel') {
            return $this->handleHotel($message, $owner->id, $extracted, $event, $confidence);
        }

        if ($kind === 'flight' || $kind === 'train') {
            return $this->handleItinerary($message, $owner->id, $extracted, $event, $confidence, $kind);
        }

        $this->fail($message, $detection['type'], "Unsupported parsed kind '{$kind}'", $owner->id, $confidence);
        return false;
    }

    /**
     * @param array<string, mixed> $extracted
     */
    private function handleHotel(
        EmailMessage $message,
        int $userId,
        array $extracted,
        string $event,
        float $confidence,
    ): bool {
        if ($event === 'cancel') {
            $cancelled = $this->hotelStays->cancelFromImport(
                $userId,
                isset($extracted['confirmation_code']) ? (string) $extracted['confirmation_code'] : null,
                isset($extracted['property_name']) ? (string) $extracted['property_name'] : null,
                isset($extracted['check_in']) ? (string) $extracted['check_in'] : null,
                isset($extracted['check_out']) ? (string) $extracted['check_out'] : null,
                $userId,
            );
            if ($cancelled === null) {
                $this->fail($message, 'hotel', 'Cancel email matched no existing hotel stay', $userId, $confidence);
                return false;
            }
            $this->notifications->create(
                $userId,
                null,
                'hotel_import',
                'A hotel stay was cancelled from an email notice. Confirmation was '
                . ($cancelled->confirmationCode ?? 'unknown') . '.'
            );
            return $this->succeed($message, 'hotel', $confidence, $userId, null, 'Hotel stay cancelled from email');
        }

        if (($extracted['check_in'] ?? null) === null
            || ($extracted['check_out'] ?? null) === null
            || ($extracted['property_name'] ?? null) === null
        ) {
            $this->fail($message, 'hotel', 'Missing required field (property_name/check_in/check_out) after parse', $userId, $confidence);
            return false;
        }

        $propertyName = (string) $extracted['property_name'];
        $city = isset($extracted['city']) && is_string($extracted['city']) ? $extracted['city'] : null;
        $state = isset($extracted['state_region']) && is_string($extracted['state_region']) ? $extracted['state_region'] : null;
        $property = $this->hotelProperties->findOrCreate(
            $propertyName,
            $city,
            $state,
            $userId,
            $userId,
            isset($extracted['brand']) && is_string($extracted['brand']) ? $extracted['brand'] : null,
            isset($extracted['address']) && is_string($extracted['address']) ? $extracted['address'] : null,
            null,
        );

        $result = $this->hotelStays->upsertFromImport(new HotelStay(
            id: null,
            userId: $userId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: (string) $extracted['check_in'],
            stayEnd: (string) $extracted['check_out'],
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: 'email_import',
            confirmationCode: isset($extracted['confirmation_code']) ? (string) $extracted['confirmation_code'] : null,
            wouldReturn: null,
            notes: 'Auto-imported from a forwarded confirmation email. Review and fill in amenities/rating.',
        ), $userId);

        $verb = $result['created'] ? 'found' : 'updated';
        $this->notifications->create(
            $userId,
            null,
            'hotel_import',
            "We {$verb} a hotel stay at {$property->hotelName} ({$result['stay']->stayStart} to {$result['stay']->stayEnd}). Review it in Hotel Stays."
        );

        return $this->succeed(
            $message,
            'hotel',
            $confidence,
            $userId,
            null,
            'Hotel stay imported'
        );
    }

    /**
     * @param array<string, mixed> $extracted
     */
    private function handleItinerary(
        EmailMessage $message,
        int $userId,
        array $extracted,
        string $event,
        float $confidence,
        string $kind,
    ): bool {
        $code = isset($extracted['confirmation_code']) ? strtoupper(trim((string) $extracted['confirmation_code'])) : '';
        if ($code === '') {
            $this->fail($message, $kind, 'Missing confirmation/PNR code', $userId, $confidence);
            return false;
        }

        if ($event === 'cancel') {
            $count = $this->trips->cancelByConfirmation($userId, $code, $userId);
            if ($count === 0) {
                $this->fail($message, $kind, "Cancel email matched no segments for confirmation {$code}", $userId, $confidence);
                return false;
            }
            $this->notifications->create(
                $userId,
                null,
                'trip_import',
                "Itinerary {$code} was cancelled from an email notice ({$count} segment(s))."
            );
            return $this->succeed($message, $kind, $confidence, $userId, null, 'Itinerary cancelled');
        }

        /** @var list<array<string, mixed>> $rawSegments */
        $rawSegments = is_array($extracted['segments'] ?? null) ? $extracted['segments'] : [];
        if ($rawSegments === []) {
            $this->fail($message, $kind, 'No flight/train segments extracted', $userId, $confidence);
            return false;
        }

        $legs = [];
        foreach ($rawSegments as $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $iata = isset($seg['carrier_iata']) && is_string($seg['carrier_iata']) && $seg['carrier_iata'] !== ''
                ? strtoupper($seg['carrier_iata'])
                : null;
            $carrierName = isset($seg['carrier_name']) && is_string($seg['carrier_name'])
                ? $seg['carrier_name']
                : null;

            if ($iata === null && $kind === 'train') {
                $iata = '2V';
                $carrierName = $carrierName ?? 'Amtrak';
            }

            $carrierId = null;
            $displayName = $carrierName;
            if ($iata !== null) {
                $carrier = $this->carriers->findOrCreateByIata(
                    $userId,
                    $iata,
                    $carrierName ?? (self::CARRIER_NAMES[$iata] ?? $iata),
                    $userId,
                    $kind === 'train' ? \NexWaypoint\Trips\Carrier::TYPE_RAIL : \NexWaypoint\Trips\Carrier::TYPE_AIRLINE,
                );
                $carrierId = $carrier->id;
                $displayName = $carrier->name;
            }

            $flightNumber = $seg['flight_number'] ?? null;
            if (is_string($flightNumber) && $iata !== null) {
                // Store digits-only when possible; keep train numbers as-is.
                $normalized = preg_replace('/[^0-9]/', '', $flightNumber);
                if ($normalized !== null && $normalized !== '' && $kind === 'flight') {
                    $flightNumber = ltrim($normalized, '0') ?: '0';
                }
            }

            $legs[] = [
                'segment_type' => (string) ($seg['segment_type'] ?? ($kind === 'train' ? 'train' : 'flight')),
                'carrier_id' => $carrierId,
                'carrier' => $displayName,
                'flight_number' => is_string($flightNumber) || is_int($flightNumber) ? (string) $flightNumber : null,
                'origin' => isset($seg['origin']) && is_string($seg['origin']) ? $seg['origin'] : null,
                'destination' => isset($seg['destination']) && is_string($seg['destination']) ? $seg['destination'] : null,
                'depart_dt' => isset($seg['depart_dt']) && is_string($seg['depart_dt']) ? $seg['depart_dt'] : null,
                'arrive_dt' => isset($seg['arrive_dt']) && is_string($seg['arrive_dt']) ? $seg['arrive_dt'] : null,
                'confirmation_code' => $code,
            ];
        }

        if ($legs === []) {
            $this->fail($message, $kind, 'No usable segments after carrier resolution', $userId, $confidence);
            return false;
        }

        $result = $this->trips->upsertItineraryByConfirmation($userId, $code, $legs, null, $userId, null);
        $verb = $result['created'] ? 'imported' : 'updated';
        $dest = $result['trip']->destinationCity;
        $this->notifications->create(
            $userId,
            $result['segments'][0]->id ?? null,
            'trip_import',
            "We {$verb} itinerary {$code} to {$dest} (" . count($result['segments']) . ' segment(s)).'
        );

        return $this->succeed(
            $message,
            $kind,
            $confidence,
            $userId,
            $result['segments'][0]->id ?? null,
            "Itinerary {$verb}"
        );
    }

    private function resolveParser(string $type, string $domain): ?ParserInterface
    {
        $domain = strtolower($domain);

        if ($type === 'flight') {
            if (str_ends_with($domain, 'aa.com')) {
                return new AmericanAirlinesParser();
            }
            if (str_ends_with($domain, 'united.com')) {
                return new UnitedAirlinesParser();
            }
            if (str_ends_with($domain, 'delta.com')) {
                return new DeltaAirlinesParser();
            }
            if (str_ends_with($domain, 'flybreeze.com')) {
                return new BreezeAirlinesParser();
            }
            return null;
        }

        if ($type === 'hotel') {
            if (str_ends_with($domain, 'hilton.com')) {
                return new HiltonHotelParser();
            }
            if (str_ends_with($domain, 'marriott.com')) {
                return new MarriottHotelParser();
            }
            return new GenericHotelConfirmationParser();
        }

        if ($type === 'train') {
            return new AmtrakParser();
        }

        return null;
    }

    private function ignore(
        EmailMessage $message,
        string $detectedType,
        string $reason,
        ?int $matchedUserId = null,
        ?float $confidence = null,
    ): bool {
        $this->source->markProcessed($message->uid);
        $this->parseLog->record(
            $message->receivedAt,
            $message->fromAddress,
            $message->subject,
            $message->uid,
            $this->sourceName,
            $detectedType,
            'ignored',
            $reason,
            $confidence,
            $matchedUserId,
            null,
        );
        $this->logger->info('Message ignored', ['uid' => $message->uid, 'reason' => $reason]);
        return true;
    }

    private function succeed(
        EmailMessage $message,
        string $detectedType,
        float $confidence,
        int $matchedUserId,
        ?int $tripSegmentId,
        string $logMessage,
    ): bool {
        $this->source->markProcessed($message->uid);
        $this->parseLog->record(
            $message->receivedAt,
            $message->fromAddress,
            $message->subject,
            $message->uid,
            $this->sourceName,
            $detectedType,
            'success',
            null,
            $confidence,
            $matchedUserId,
            $tripSegmentId,
        );
        $this->logger->info($logMessage, ['uid' => $message->uid, 'user_id' => $matchedUserId]);
        return true;
    }

    private function fail(EmailMessage $message, string $detectedType, string $reason, ?int $matchedUserId = null, ?float $confidence = null): void
    {
        $this->lastFailureReason = $reason;
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
