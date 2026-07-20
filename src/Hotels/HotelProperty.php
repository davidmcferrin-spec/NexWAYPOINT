<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

final class HotelProperty
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
        public readonly ?string $phone,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly bool $hasDesk,
        public readonly ?string $deskNotes,
        public readonly bool $hasPool,
        public readonly bool $hasHotTub,
        public readonly bool $hasBreakfast,
        public readonly ?string $breakfastNotes,
        public readonly bool $hasGym,
        public readonly bool $hasFreeParking,
        public readonly bool $hasAirportShuttle,
        public readonly bool $hasEvCharging,
        public readonly bool $hasOnsiteRestaurant,
        public readonly bool $hasOffsiteGym,
        public readonly bool $walkToOffice,
        public readonly ?string $walkToOfficeNotes,
        public readonly bool $hasDestinationFee,
        public readonly ?string $destinationFeeNotes,
        public readonly ?int $wifiQuality,
        public readonly ?int $noiseLevel,
        public readonly ?string $uniqueFeatures,
        public readonly bool $isBlacklisted,
        public readonly ?string $blacklistReason,
        public readonly ?float $overallRating = null,
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
            phone: $row['phone'] ?? null,
            latitude: isset($row['latitude']) ? (float) $row['latitude'] : null,
            longitude: isset($row['longitude']) ? (float) $row['longitude'] : null,
            hasDesk: (bool) $row['has_desk'],
            deskNotes: $row['desk_notes'] ?? null,
            hasPool: (bool) $row['has_pool'],
            hasHotTub: (bool) $row['has_hot_tub'],
            hasBreakfast: (bool) $row['has_breakfast'],
            breakfastNotes: $row['breakfast_notes'] ?? null,
            hasGym: (bool) $row['has_gym'],
            hasFreeParking: (bool) $row['has_free_parking'],
            hasAirportShuttle: (bool) $row['has_airport_shuttle'],
            hasEvCharging: (bool) ($row['has_ev_charging'] ?? false),
            hasOnsiteRestaurant: (bool) ($row['has_onsite_restaurant'] ?? false),
            hasOffsiteGym: (bool) ($row['has_offsite_gym'] ?? false),
            walkToOffice: (bool) ($row['walk_to_office'] ?? false),
            walkToOfficeNotes: $row['walk_to_office_notes'] ?? null,
            hasDestinationFee: (bool) ($row['has_destination_fee'] ?? false),
            destinationFeeNotes: $row['destination_fee_notes'] ?? null,
            wifiQuality: isset($row['wifi_quality']) ? (int) $row['wifi_quality'] : null,
            noiseLevel: isset($row['noise_level']) ? (int) $row['noise_level'] : null,
            uniqueFeatures: $row['unique_features'] ?? null,
            isBlacklisted: (bool) $row['is_blacklisted'],
            blacklistReason: $row['blacklist_reason'] ?? null,
            overallRating: isset($row['overall_rating']) ? (float) $row['overall_rating'] : null,
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
            'phone' => $this->phone,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'has_desk' => $this->hasDesk,
            'desk_notes' => $this->deskNotes,
            'has_pool' => $this->hasPool,
            'has_hot_tub' => $this->hasHotTub,
            'has_breakfast' => $this->hasBreakfast,
            'breakfast_notes' => $this->breakfastNotes,
            'has_gym' => $this->hasGym,
            'has_free_parking' => $this->hasFreeParking,
            'has_airport_shuttle' => $this->hasAirportShuttle,
            'has_ev_charging' => $this->hasEvCharging,
            'has_onsite_restaurant' => $this->hasOnsiteRestaurant,
            'has_offsite_gym' => $this->hasOffsiteGym,
            'walk_to_office' => $this->walkToOffice,
            'walk_to_office_notes' => $this->walkToOfficeNotes,
            'has_destination_fee' => $this->hasDestinationFee,
            'destination_fee_notes' => $this->destinationFeeNotes,
            'wifi_quality' => $this->wifiQuality,
            'noise_level' => $this->noiseLevel,
            'unique_features' => $this->uniqueFeatures,
            'is_blacklisted' => $this->isBlacklisted,
            'blacklist_reason' => $this->blacklistReason,
            'overall_rating' => $this->overallRating,
        ];
    }

    public function label(): string
    {
        $city = $this->city !== null && $this->city !== '' ? ", {$this->city}" : '';
        return $this->hotelName . $city;
    }

    /** Stable key for City, State location filtering. */
    public function locationKey(): ?string
    {
        $city = trim((string) $this->city);
        if ($city === '') {
            return null;
        }
        $state = trim((string) $this->stateRegion);
        return self::makeLocationKey($city, $state !== '' ? $state : null);
    }

    public function locationLabel(): ?string
    {
        $city = trim((string) $this->city);
        if ($city === '') {
            return null;
        }
        $state = trim((string) $this->stateRegion);
        return $state !== '' ? "{$city}, {$state}" : $city;
    }

    public static function makeLocationKey(string $city, ?string $stateRegion): string
    {
        return strtolower(trim($city)) . '|' . strtolower(trim((string) $stateRegion));
    }

    /**
     * @return array{city: string, state_region: string}
     */
    public static function parseLocationKey(string $key): array
    {
        $parts = explode('|', $key, 2);
        return [
            'city' => $parts[0] ?? '',
            'state_region' => $parts[1] ?? '',
        ];
    }
}
