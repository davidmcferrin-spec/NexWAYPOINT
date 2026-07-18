<?php

declare(strict_types=1);

namespace NexWaypont\Hotels;

/**
 * Value object for a single hotel_stays row. Immutable by convention --
 * build a new instance (fromArray) after any repository write rather than
 * mutating in place, so callers can't drift from what's actually in the DB.
 */
final class HotelStay
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        public readonly string $hotelName,
        public readonly ?string $brand,
        public readonly ?string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $city,
        public readonly ?string $stateRegion,
        public readonly ?string $postalCode,
        public readonly ?string $country,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $roomNumber,
        public readonly string $stayStart,
        public readonly string $stayEnd,
        public readonly ?int $rating,
        public readonly bool $hasDesk,
        public readonly ?string $deskNotes,
        public readonly bool $hasPool,
        public readonly bool $hasHotTub,
        public readonly bool $hasBreakfast,
        public readonly ?string $breakfastNotes,
        public readonly bool $hasGym,
        public readonly bool $hasFreeParking,
        public readonly bool $hasAirportShuttle,
        public readonly ?int $wifiQuality,
        public readonly ?int $noiseLevel,
        public readonly ?string $uniqueFeatures,
        public readonly bool $isBlacklisted,
        public readonly ?string $blacklistReason,
        public readonly ?float $lastStayPrice,
        public readonly string $currency,
        public readonly ?string $bookingSource,
        public readonly ?string $confirmationCode,
        public readonly ?bool $wouldReturn,
        public readonly ?string $notes,
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
            hotelName: (string) $row['hotel_name'],
            brand: $row['brand'] ?? null,
            addressLine1: $row['address_line1'] ?? null,
            addressLine2: $row['address_line2'] ?? null,
            city: $row['city'] ?? null,
            stateRegion: $row['state_region'] ?? null,
            postalCode: $row['postal_code'] ?? null,
            country: $row['country'] ?? null,
            latitude: isset($row['latitude']) ? (float) $row['latitude'] : null,
            longitude: isset($row['longitude']) ? (float) $row['longitude'] : null,
            roomNumber: $row['room_number'] ?? null,
            stayStart: (string) $row['stay_start'],
            stayEnd: (string) $row['stay_end'],
            rating: isset($row['rating']) ? (int) $row['rating'] : null,
            hasDesk: (bool) $row['has_desk'],
            deskNotes: $row['desk_notes'] ?? null,
            hasPool: (bool) $row['has_pool'],
            hasHotTub: (bool) $row['has_hot_tub'],
            hasBreakfast: (bool) $row['has_breakfast'],
            breakfastNotes: $row['breakfast_notes'] ?? null,
            hasGym: (bool) $row['has_gym'],
            hasFreeParking: (bool) $row['has_free_parking'],
            hasAirportShuttle: (bool) $row['has_airport_shuttle'],
            wifiQuality: isset($row['wifi_quality']) ? (int) $row['wifi_quality'] : null,
            noiseLevel: isset($row['noise_level']) ? (int) $row['noise_level'] : null,
            uniqueFeatures: $row['unique_features'] ?? null,
            isBlacklisted: (bool) $row['is_blacklisted'],
            blacklistReason: $row['blacklist_reason'] ?? null,
            lastStayPrice: isset($row['last_stay_price']) ? (float) $row['last_stay_price'] : null,
            currency: (string) ($row['currency'] ?? 'USD'),
            bookingSource: $row['booking_source'] ?? null,
            confirmationCode: $row['confirmation_code'] ?? null,
            wouldReturn: isset($row['would_return']) ? (bool) $row['would_return'] : null,
            notes: $row['notes'] ?? null,
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
            'hotel_name' => $this->hotelName,
            'brand' => $this->brand,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'city' => $this->city,
            'state_region' => $this->stateRegion,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'room_number' => $this->roomNumber,
            'stay_start' => $this->stayStart,
            'stay_end' => $this->stayEnd,
            'rating' => $this->rating,
            'has_desk' => $this->hasDesk,
            'desk_notes' => $this->deskNotes,
            'has_pool' => $this->hasPool,
            'has_hot_tub' => $this->hasHotTub,
            'has_breakfast' => $this->hasBreakfast,
            'breakfast_notes' => $this->breakfastNotes,
            'has_gym' => $this->hasGym,
            'has_free_parking' => $this->hasFreeParking,
            'has_airport_shuttle' => $this->hasAirportShuttle,
            'wifi_quality' => $this->wifiQuality,
            'noise_level' => $this->noiseLevel,
            'unique_features' => $this->uniqueFeatures,
            'is_blacklisted' => $this->isBlacklisted,
            'blacklist_reason' => $this->blacklistReason,
            'last_stay_price' => $this->lastStayPrice,
            'currency' => $this->currency,
            'booking_source' => $this->bookingSource,
            'confirmation_code' => $this->confirmationCode,
            'would_return' => $this->wouldReturn,
            'notes' => $this->notes,
        ];
    }
}
