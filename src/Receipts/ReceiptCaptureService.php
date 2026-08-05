<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

/**
 * Archives expense receipts from mail import only:
 * vendor PDF attachment when present, else a PDF of the vendor email body.
 */
final class ReceiptCaptureService
{
    public function __construct(
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ReceiptFileStore $files,
        private readonly EmailReceiptPdfBuilder $emailPdf,
        private readonly TripRepository $trips,
        private readonly HotelStayRepository $stays,
        private readonly HotelPropertyRepository $properties,
        private readonly Logger $logger,
        private readonly int $retentionDays = 90,
    ) {
    }

    public static function retentionDaysFromEnv(): int
    {
        return max(30, (int) Env::get('RECEIPT_RETENTION_DAYS', '90'));
    }

    public function expiresAt(?\DateTimeImmutable $from = null): string
    {
        $from ??= new \DateTimeImmutable('now');
        $days = max(1, $this->retentionDays);
        return $from->modify("+{$days} days")->format('Y-m-d H:i:s');
    }

    /**
     * After a successful confirm/change import: prefer vendor PDF attachment,
     * else PDF of the vendor confirmation email itself.
     */
    public function captureFromImport(
        EmailMessage $message,
        int $ownerUserId,
        string $kind,
        ?int $tripId,
        ?int $hotelStayId,
        ?int $parseLogId,
        ?int $actorUserId = null,
    ): ?ExpenseReceipt {
        try {
            $meta = $this->metaFromLinks($ownerUserId, $kind, $tripId, $hotelStayId);

            $pdfAttachment = $this->firstPdfAttachment($message);
            if ($pdfAttachment !== null) {
                return $this->storeNew(
                    ownerUserId: $ownerUserId,
                    kind: $meta['kind'],
                    brand: $meta['brand'],
                    locationLabel: $meta['location_label'],
                    travelDate: $meta['travel_date'],
                    travelEndDate: $meta['travel_end_date'],
                    confirmationCode: $meta['confirmation_code'],
                    amount: $meta['amount'],
                    currency: $meta['currency'],
                    tripId: $tripId,
                    hotelStayId: $hotelStayId,
                    parseLogId: $parseLogId,
                    source: ExpenseReceipt::SOURCE_ATTACHMENT,
                    title: $meta['title'] . ' (vendor PDF)',
                    originalFilename: $pdfAttachment['filename'],
                    mimeType: 'application/pdf',
                    bytes: $pdfAttachment['content'],
                    extension: 'pdf',
                    actorUserId: $actorUserId,
                );
            }

            if (!$this->emailPdf->hasRenderableBody($message)) {
                $this->logger->info('No vendor PDF attachment or email body for receipt', [
                    'user_id' => $ownerUserId,
                    'trip_id' => $tripId,
                    'hotel_stay_id' => $hotelStayId,
                ]);
                return null;
            }

            $bytes = $this->emailPdf->build($message);
            $filename = $this->safeFilename($meta['title'] . '-vendor-email') . '.pdf';

            return $this->storeNew(
                ownerUserId: $ownerUserId,
                kind: $meta['kind'],
                brand: $meta['brand'],
                locationLabel: $meta['location_label'],
                travelDate: $meta['travel_date'],
                travelEndDate: $meta['travel_end_date'],
                confirmationCode: $meta['confirmation_code'],
                amount: $meta['amount'],
                currency: $meta['currency'],
                tripId: $tripId,
                hotelStayId: $hotelStayId,
                parseLogId: $parseLogId,
                source: ExpenseReceipt::SOURCE_EMAIL_BODY,
                title: $meta['title'] . ' (vendor email)',
                originalFilename: $filename,
                mimeType: 'application/pdf',
                bytes: $bytes,
                extension: 'pdf',
                actorUserId: $actorUserId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Receipt capture from import failed', [
                'error' => $e->getMessage(),
                'user_id' => $ownerUserId,
                'trip_id' => $tripId,
                'hotel_stay_id' => $hotelStayId,
            ]);
        }
        return null;
    }

    public function purgeExpired(?\DateTimeImmutable $asOf = null): int
    {
        $rows = $this->receipts->findExpired($asOf);
        if ($rows === []) {
            return 0;
        }
        $ids = $this->files->deleteFilesForRows($rows);
        $this->receipts->deleteByIds($ids);
        $this->logger->info('Purged expired expense receipts', ['count' => count($ids)]);
        return count($ids);
    }

    private function storeNew(
        int $ownerUserId,
        string $kind,
        ?string $brand,
        string $locationLabel,
        string $travelDate,
        ?string $travelEndDate,
        ?string $confirmationCode,
        ?float $amount,
        ?string $currency,
        ?int $tripId,
        ?int $hotelStayId,
        ?int $parseLogId,
        string $source,
        string $title,
        ?string $originalFilename,
        string $mimeType,
        string $bytes,
        string $extension,
        ?int $actorUserId,
    ): ExpenseReceipt {
        $stored = $this->files->writeBytes($bytes, $extension);
        return $this->receipts->create(new ExpenseReceipt(
            id: null,
            ownerUserId: $ownerUserId,
            kind: $kind,
            brand: $brand,
            locationLabel: $locationLabel,
            travelDate: $travelDate,
            travelEndDate: $travelEndDate,
            confirmationCode: $confirmationCode,
            amount: $amount,
            currency: $currency,
            tripId: $tripId,
            hotelStayId: $hotelStayId,
            parseLogId: $parseLogId,
            source: $source,
            title: $title,
            originalFilename: $originalFilename,
            mimeType: $mimeType,
            filePath: $stored['file_path'],
            fileSize: $stored['file_size'],
            expiresAt: $this->expiresAt(),
        ), $actorUserId);
    }

    /**
     * @return array{
     *   kind: string,
     *   brand: ?string,
     *   location_label: string,
     *   travel_date: string,
     *   travel_end_date: ?string,
     *   confirmation_code: ?string,
     *   amount: ?float,
     *   currency: ?string,
     *   title: string
     * }
     */
    private function metaFromLinks(int $ownerUserId, string $kind, ?int $tripId, ?int $hotelStayId): array
    {
        if ($tripId !== null) {
            $trip = $this->trips->find($tripId);
            if ($trip !== null && $trip->ownerId === $ownerUserId) {
                $segments = array_values(array_filter(
                    $this->trips->segmentsForTrip((int) $trip->id),
                    static fn (TripSegment $s): bool => $s->status !== 'cancelled'
                ));
                $metaKind = ExpenseReceipt::KIND_FLIGHT;
                $brand = null;
                $confirmation = null;
                foreach ($segments as $segment) {
                    if (in_array($segment->segmentType, ['flight', 'train'], true)) {
                        $metaKind = $segment->segmentType === 'train'
                            ? ExpenseReceipt::KIND_TRAIN
                            : ExpenseReceipt::KIND_FLIGHT;
                        $brand = $segment->carrier ?? $brand;
                        $confirmation = $segment->confirmationCode ?? $confirmation;
                        break;
                    }
                }
                return [
                    'kind' => $metaKind,
                    'brand' => $brand,
                    'location_label' => $trip->destinationCity,
                    'travel_date' => $trip->startDate,
                    'travel_end_date' => $trip->endDate,
                    'confirmation_code' => $confirmation,
                    'amount' => null,
                    'currency' => null,
                    'title' => ($metaKind === ExpenseReceipt::KIND_TRAIN ? 'Train' : 'Flight')
                        . ' · ' . $trip->destinationCity,
                ];
            }
        }
        if ($hotelStayId !== null) {
            $stay = $this->stays->find($hotelStayId);
            if ($stay !== null && $stay->userId === $ownerUserId) {
                $property = $this->properties->find($stay->hotelPropertyId);
                $name = $property?->hotelName ?? 'Hotel stay';
                $cityBits = array_filter([
                    $property?->city,
                    $property?->stateRegion,
                ], static fn ($v) => is_string($v) && trim($v) !== '');
                $location = $cityBits !== [] ? implode(', ', $cityBits) : $name;
                return [
                    'kind' => ExpenseReceipt::KIND_HOTEL,
                    'brand' => $property?->brand,
                    'location_label' => $location,
                    'travel_date' => $stay->stayStart,
                    'travel_end_date' => $stay->stayEnd,
                    'confirmation_code' => $stay->confirmationCode,
                    'amount' => $stay->lastStayPrice,
                    'currency' => $stay->currency,
                    'title' => 'Hotel · ' . $name,
                ];
            }
        }
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $metaKind = in_array($kind, [
            ExpenseReceipt::KIND_FLIGHT,
            ExpenseReceipt::KIND_TRAIN,
            ExpenseReceipt::KIND_HOTEL,
        ], true) ? $kind : ExpenseReceipt::KIND_OTHER;
        return [
            'kind' => $metaKind,
            'brand' => null,
            'location_label' => 'Travel',
            'travel_date' => $today,
            'travel_end_date' => null,
            'confirmation_code' => null,
            'amount' => null,
            'currency' => null,
            'title' => 'Travel receipt',
        ];
    }

    /**
     * @return array{filename: string, content: string}|null
     */
    private function firstPdfAttachment(EmailMessage $message): ?array
    {
        foreach ($message->attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $mime = strtolower((string) ($att['mime_type'] ?? ''));
            $name = (string) ($att['filename'] ?? 'receipt.pdf');
            $content = (string) ($att['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $isPdf = $mime === 'application/pdf'
                || str_ends_with(strtolower($name), '.pdf')
                || str_starts_with($content, '%PDF');
            if ($isPdf) {
                return ['filename' => $name !== '' ? $name : 'receipt.pdf', 'content' => $content];
            }
        }
        return null;
    }

    private function safeFilename(string $title): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $title) ?? 'receipt';
        $safe = trim($safe, '-');
        return $safe !== '' ? $safe : 'receipt';
    }
}
