<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

final class ExpenseReceipt
{
    public const KIND_FLIGHT = 'flight';
    public const KIND_TRAIN = 'train';
    public const KIND_HOTEL = 'hotel';
    public const KIND_OTHER = 'other';

    public const SOURCE_GENERATED = 'generated';
    public const SOURCE_ATTACHMENT = 'attachment';
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_EMAIL_BODY = 'email_body';

    public function __construct(
        public readonly ?int $id,
        public readonly int $ownerUserId,
        public readonly string $kind,
        public readonly ?string $brand,
        public readonly string $locationLabel,
        public readonly string $travelDate,
        public readonly ?string $travelEndDate,
        public readonly ?string $confirmationCode,
        public readonly ?float $amount,
        public readonly ?string $currency,
        public readonly ?int $tripId,
        public readonly ?int $hotelStayId,
        public readonly ?int $parseLogId,
        public readonly string $source,
        public readonly string $title,
        public readonly ?string $originalFilename,
        public readonly string $mimeType,
        public readonly string $filePath,
        public readonly int $fileSize,
        public readonly string $expiresAt,
        public readonly ?string $createdAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            ownerUserId: (int) $row['owner_user_id'],
            kind: (string) $row['kind'],
            brand: isset($row['brand']) && $row['brand'] !== '' ? (string) $row['brand'] : null,
            locationLabel: (string) $row['location_label'],
            travelDate: (string) $row['travel_date'],
            travelEndDate: isset($row['travel_end_date']) && $row['travel_end_date'] !== ''
                ? (string) $row['travel_end_date'] : null,
            confirmationCode: isset($row['confirmation_code']) && $row['confirmation_code'] !== ''
                ? (string) $row['confirmation_code'] : null,
            amount: isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== ''
                ? (float) $row['amount'] : null,
            currency: isset($row['currency']) && $row['currency'] !== ''
                ? (string) $row['currency'] : null,
            tripId: isset($row['trip_id']) && $row['trip_id'] !== null && $row['trip_id'] !== ''
                ? (int) $row['trip_id'] : null,
            hotelStayId: isset($row['hotel_stay_id']) && $row['hotel_stay_id'] !== null && $row['hotel_stay_id'] !== ''
                ? (int) $row['hotel_stay_id'] : null,
            parseLogId: isset($row['parse_log_id']) && $row['parse_log_id'] !== null && $row['parse_log_id'] !== ''
                ? (int) $row['parse_log_id'] : null,
            source: (string) $row['source'],
            title: (string) $row['title'],
            originalFilename: isset($row['original_filename']) && $row['original_filename'] !== ''
                ? (string) $row['original_filename'] : null,
            mimeType: (string) ($row['mime_type'] ?? 'application/pdf'),
            filePath: (string) $row['file_path'],
            fileSize: (int) ($row['file_size'] ?? 0),
            expiresAt: (string) $row['expires_at'],
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_user_id' => $this->ownerUserId,
            'kind' => $this->kind,
            'brand' => $this->brand,
            'location_label' => $this->locationLabel,
            'travel_date' => $this->travelDate,
            'travel_end_date' => $this->travelEndDate,
            'confirmation_code' => $this->confirmationCode,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'trip_id' => $this->tripId,
            'hotel_stay_id' => $this->hotelStayId,
            'parse_log_id' => $this->parseLogId,
            'source' => $this->source,
            'title' => $this->title,
            'original_filename' => $this->originalFilename,
            'mime_type' => $this->mimeType,
            'file_path' => $this->filePath,
            'file_size' => $this->fileSize,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
        ];
    }

    public function downloadFilename(): string
    {
        if ($this->originalFilename !== null && trim($this->originalFilename) !== '') {
            return basename($this->originalFilename);
        }
        $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $this->title) ?? 'receipt';
        $slug = trim($slug, '-') ?: 'receipt';
        $ext = match ($this->mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
        return $slug . '.' . $ext;
    }
}
