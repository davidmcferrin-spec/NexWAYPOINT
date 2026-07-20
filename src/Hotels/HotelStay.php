<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

/**
 * Value object for a single hotel_stays row (one visit). Property identity and
 * amenities live on HotelProperty; room # / bed / bath / stay_rating are here.
 */
final class HotelStay
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        public readonly int $hotelPropertyId,
        public readonly ?string $roomNumber,
        public readonly ?string $bedType,
        public readonly ?string $bathroomType,
        public readonly string $stayStart,
        public readonly string $stayEnd,
        public readonly ?int $stayRating,
        public readonly ?float $lastStayPrice,
        public readonly string $currency,
        public readonly ?string $bookingSource,
        public readonly ?string $confirmationCode,
        public readonly ?bool $wouldReturn,
        public readonly ?string $notes,
        public readonly bool $isPrivate = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            userId: (int) $row['user_id'],
            hotelPropertyId: (int) $row['hotel_property_id'],
            roomNumber: $row['room_number'] ?? null,
            bedType: $row['bed_type'] ?? null,
            bathroomType: $row['bathroom_type'] ?? null,
            stayStart: (string) $row['stay_start'],
            stayEnd: (string) $row['stay_end'],
            stayRating: isset($row['stay_rating']) ? (int) $row['stay_rating'] : null,
            lastStayPrice: isset($row['last_stay_price']) ? (float) $row['last_stay_price'] : null,
            currency: (string) ($row['currency'] ?? 'USD'),
            bookingSource: $row['booking_source'] ?? null,
            confirmationCode: $row['confirmation_code'] ?? null,
            wouldReturn: isset($row['would_return']) ? (bool) $row['would_return'] : null,
            notes: $row['notes'] ?? null,
            isPrivate: (bool) ($row['is_private'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'hotel_property_id' => $this->hotelPropertyId,
            'room_number' => $this->roomNumber,
            'bed_type' => $this->bedType,
            'bathroom_type' => $this->bathroomType,
            'stay_start' => $this->stayStart,
            'stay_end' => $this->stayEnd,
            'stay_rating' => $this->stayRating,
            'last_stay_price' => $this->lastStayPrice,
            'currency' => $this->currency,
            'booking_source' => $this->bookingSource,
            'confirmation_code' => $this->confirmationCode,
            'would_return' => $this->wouldReturn,
            'notes' => $this->notes,
            'is_private' => $this->isPrivate,
        ];
    }
}
