<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Trips\TripRepository;

/**
 * Creates / replaces expense receipts from mail import, trip/stay generate, or upload.
 */
final class ReceiptCaptureService
{
    public function __construct(
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ReceiptFileStore $files,
        private readonly ReceiptPdfBuilder $pdfBuilder,
        private readonly TripRepository $trips,
        private readonly HotelStayRepository $stays,
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
     * After a successful confirm/change import: prefer vendor PDF attachment, else generate.
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
            $pdfAttachment = $this->firstPdfAttachment($message);
            if ($pdfAttachment !== null) {
                $meta = $this->metaFromLinks($ownerUserId, $kind, $tripId, $hotelStayId);
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

            if ($tripId !== null) {
                return $this->generateForTrip($tripId, $ownerUserId, $parseLogId, $actorUserId);
            }
            if ($hotelStayId !== null) {
                return $this->generateForStay($hotelStayId, $ownerUserId, $parseLogId, $actorUserId);
            }
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

    public function generateForTrip(
        int $tripId,
        int $ownerUserId,
        ?int $parseLogId = null,
        ?int $actorUserId = null,
    ): ExpenseReceipt {
        $trip = $this->trips->find($tripId);
        if ($trip === null || $trip->ownerId !== $ownerUserId) {
            throw new \RuntimeException('Trip not found for receipt generation');
        }
        $built = $this->pdfBuilder->buildForTrip($trip);
        return $this->upsertGenerated(
            ownerUserId: $ownerUserId,
            existing: $this->receipts->findGeneratedForTrip($ownerUserId, $tripId),
            built: $built,
            parseLogId: $parseLogId,
            actorUserId: $actorUserId,
        );
    }

    public function generateForStay(
        int $hotelStayId,
        int $ownerUserId,
        ?int $parseLogId = null,
        ?int $actorUserId = null,
    ): ExpenseReceipt {
        $stay = $this->stays->find($hotelStayId);
        if ($stay === null || $stay->userId !== $ownerUserId) {
            throw new \RuntimeException('Hotel stay not found for receipt generation');
        }
        $built = $this->pdfBuilder->buildForStay($stay);
        return $this->upsertGenerated(
            ownerUserId: $ownerUserId,
            existing: $this->receipts->findGeneratedForStay($ownerUserId, $hotelStayId),
            built: $built,
            parseLogId: $parseLogId,
            actorUserId: $actorUserId,
        );
    }

    /**
     * @param array{
     *   name: string,
     *   type: string,
     *   tmp_name: string,
     *   error: int,
     *   size: int
     * } $file $_FILES element
     */
    public function storeUpload(
        int $ownerUserId,
        array $file,
        string $kind,
        string $locationLabel,
        string $travelDate,
        ?string $travelEndDate,
        ?string $brand,
        ?string $confirmationCode,
        ?float $amount,
        ?string $currency,
        ?int $tripId,
        ?int $hotelStayId,
        ?string $title,
        ?int $actorUserId = null,
    ): ExpenseReceipt {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed (error ' . (int) ($file['error'] ?? 0) . ')');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            throw new \RuntimeException('Upload must be between 1 byte and 8 MB');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Invalid upload');
        }
        $bytes = file_get_contents($tmp);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Could not read uploaded file');
        }

        $origName = basename((string) ($file['name'] ?? 'receipt.bin'));
        [$mime, $ext] = $this->detectUploadType($bytes, (string) ($file['type'] ?? ''), $origName);

        $kind = in_array($kind, [
            ExpenseReceipt::KIND_FLIGHT,
            ExpenseReceipt::KIND_TRAIN,
            ExpenseReceipt::KIND_HOTEL,
            ExpenseReceipt::KIND_OTHER,
        ], true) ? $kind : ExpenseReceipt::KIND_OTHER;

        $title = $title !== null && trim($title) !== ''
            ? trim($title)
            : (ucfirst($kind) . ' · ' . $locationLabel);

        return $this->storeNew(
            ownerUserId: $ownerUserId,
            kind: $kind,
            brand: $brand !== null && trim($brand) !== '' ? trim($brand) : null,
            locationLabel: trim($locationLabel) !== '' ? trim($locationLabel) : '—',
            travelDate: $travelDate,
            travelEndDate: $travelEndDate,
            confirmationCode: $confirmationCode,
            amount: $amount,
            currency: $currency,
            tripId: $tripId,
            hotelStayId: $hotelStayId,
            parseLogId: null,
            source: ExpenseReceipt::SOURCE_UPLOAD,
            title: $title,
            originalFilename: $origName,
            mimeType: $mime,
            bytes: $bytes,
            extension: $ext,
            actorUserId: $actorUserId,
        );
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

    /**
     * @param array{
     *   bytes: string,
     *   kind: string,
     *   brand: ?string,
     *   location_label: string,
     *   travel_date: string,
     *   travel_end_date: ?string,
     *   confirmation_code: ?string,
     *   amount: ?float,
     *   currency: ?string,
     *   title: string,
     *   trip_id: ?int,
     *   hotel_stay_id: ?int
     * } $built
     */
    private function upsertGenerated(
        int $ownerUserId,
        ?ExpenseReceipt $existing,
        array $built,
        ?int $parseLogId,
        ?int $actorUserId,
    ): ExpenseReceipt {
        $stored = $this->files->writeBytes($built['bytes'], 'pdf');
        $expires = $this->expiresAt();
        $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $built['title']) . '.pdf';

        if ($existing !== null && $existing->id !== null) {
            $this->files->deleteRelative($existing->filePath);
            return $this->receipts->updateFileMeta(
                (int) $existing->id,
                $stored['file_path'],
                $stored['file_size'],
                'application/pdf',
                $expires,
                $filename,
                $actorUserId,
            );
        }

        return $this->receipts->create(new ExpenseReceipt(
            id: null,
            ownerUserId: $ownerUserId,
            kind: $built['kind'],
            brand: $built['brand'],
            locationLabel: $built['location_label'],
            travelDate: $built['travel_date'],
            travelEndDate: $built['travel_end_date'],
            confirmationCode: $built['confirmation_code'],
            amount: $built['amount'],
            currency: $built['currency'],
            tripId: $built['trip_id'],
            hotelStayId: $built['hotel_stay_id'],
            parseLogId: $parseLogId,
            source: ExpenseReceipt::SOURCE_GENERATED,
            title: $built['title'],
            originalFilename: $filename,
            mimeType: 'application/pdf',
            filePath: $stored['file_path'],
            fileSize: $stored['file_size'],
            expiresAt: $expires,
        ), $actorUserId);
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
                $built = $this->pdfBuilder->buildForTrip($trip);
                return [
                    'kind' => $built['kind'],
                    'brand' => $built['brand'],
                    'location_label' => $built['location_label'],
                    'travel_date' => $built['travel_date'],
                    'travel_end_date' => $built['travel_end_date'],
                    'confirmation_code' => $built['confirmation_code'],
                    'amount' => $built['amount'],
                    'currency' => $built['currency'],
                    'title' => $built['title'],
                ];
            }
        }
        if ($hotelStayId !== null) {
            $stay = $this->stays->find($hotelStayId);
            if ($stay !== null && $stay->userId === $ownerUserId) {
                $built = $this->pdfBuilder->buildForStay($stay);
                return [
                    'kind' => $built['kind'],
                    'brand' => $built['brand'],
                    'location_label' => $built['location_label'],
                    'travel_date' => $built['travel_date'],
                    'travel_end_date' => $built['travel_end_date'],
                    'confirmation_code' => $built['confirmation_code'],
                    'amount' => $built['amount'],
                    'currency' => $built['currency'],
                    'title' => $built['title'],
                ];
            }
        }
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        return [
            'kind' => in_array($kind, [
                ExpenseReceipt::KIND_FLIGHT,
                ExpenseReceipt::KIND_TRAIN,
                ExpenseReceipt::KIND_HOTEL,
            ], true) ? $kind : ExpenseReceipt::KIND_OTHER,
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

    /**
     * @return array{0: string, 1: string} [mime, extension]
     */
    private function detectUploadType(string $bytes, string $reportedMime, string $filename): array
    {
        $lower = strtolower($filename);
        if (str_starts_with($bytes, '%PDF') || str_ends_with($lower, '.pdf') || str_contains($reportedMime, 'pdf')) {
            return ['application/pdf', 'pdf'];
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF") || str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg')) {
            return ['image/jpeg', 'jpg'];
        }
        if (str_starts_with($bytes, "\x89PNG") || str_ends_with($lower, '.png')) {
            return ['image/png', 'png'];
        }
        throw new \RuntimeException('Only PDF, JPEG, or PNG receipts are accepted');
    }
}
