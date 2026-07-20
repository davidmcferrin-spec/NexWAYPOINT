<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\HotelProperty;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;

final class HotelStayRepositoryTest extends NexWaypointTestCase
{
    private HotelPropertyRepository $properties;
    private HotelStayRepository $stays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->properties = new HotelPropertyRepository($this->db, $this->logger);
        $this->stays = new HotelStayRepository($this->db, $this->logger, $this->properties);
    }

    private function makeProperty(int $userId, array $overrides = []): HotelProperty
    {
        $defaults = [
            'id' => null,
            'userId' => $userId,
            'hotelName' => 'Test Hotel Downtown',
            'brand' => 'Test Brand',
            'addressLine1' => '123 Main St',
            'addressLine2' => null,
            'city' => 'Chicago',
            'stateRegion' => 'IL',
            'postalCode' => '60601',
            'country' => 'USA',
            'phone' => null,
            'latitude' => null,
            'longitude' => null,
            'hasDesk' => true,
            'deskNotes' => null,
            'hasPool' => false,
            'hasHotTub' => false,
            'hasBreakfast' => true,
            'breakfastNotes' => null,
            'hasGym' => true,
            'hasFreeParking' => false,
            'hasAirportShuttle' => true,
            'hasEvCharging' => false,
            'hasOnsiteRestaurant' => false,
            'hasOffsiteGym' => false,
            'walkToOffice' => false,
            'walkToOfficeNotes' => null,
            'hasDestinationFee' => false,
            'destinationFeeNotes' => null,
            'wifiQuality' => 5,
            'noiseLevel' => 2,
            'uniqueFeatures' => null,
            'isBlacklisted' => false,
            'blacklistReason' => null,
            'overallRating' => null,
        ];
        return new HotelProperty(...array_merge($defaults, $overrides));
    }

    private function makeStay(int $userId, int $propertyId, array $overrides = []): HotelStay
    {
        $defaults = [
            'id' => null,
            'userId' => $userId,
            'hotelPropertyId' => $propertyId,
            'roomNumber' => '412',
            'bedType' => 'king',
            'bathroomType' => 'walk_in_shower',
            'stayStart' => '2026-08-01',
            'stayEnd' => '2026-08-03',
            'stayRating' => 4,
            'lastStayPrice' => 189.50,
            'currency' => 'USD',
            'bookingSource' => null,
            'confirmationCode' => 'ABC123',
            'wouldReturn' => true,
            'notes' => null,
            'isPrivate' => false,
        ];
        return new HotelStay(...array_merge($defaults, $overrides));
    }

    public function testCreateAndFind(): void
    {
        $userId = $this->insertUser('dave');
        $property = $this->properties->create($this->makeProperty($userId));

        $created = $this->stays->create($this->makeStay($userId, (int) $property->id));

        self::assertNotNull($created->id);
        $found = $this->stays->find($created->id);
        self::assertNotNull($found);
        self::assertSame($property->id, $found->hotelPropertyId);
        self::assertSame('king', $found->bedType);
        self::assertSame('walk_in_shower', $found->bathroomType);
        self::assertSame(4, $found->stayRating);
    }

    public function testFindForUserOrdersByStayStartDesc(): void
    {
        $userId = $this->insertUser('dave');
        $property = $this->properties->create($this->makeProperty($userId));

        $this->stays->create($this->makeStay($userId, (int) $property->id, [
            'stayStart' => '2026-01-01',
            'stayEnd' => '2026-01-02',
            'roomNumber' => '101',
        ]));
        $this->stays->create($this->makeStay($userId, (int) $property->id, [
            'stayStart' => '2026-06-01',
            'stayEnd' => '2026-06-02',
            'roomNumber' => '202',
        ]));

        $stays = $this->stays->findForUser($userId);
        self::assertCount(2, $stays);
        self::assertSame('202', $stays[0]->roomNumber);
    }

    public function testPropertyReuseByNameCity(): void
    {
        $userId = $this->insertUser('dave');
        $first = $this->properties->create($this->makeProperty($userId, [
            'hotelName' => 'Hyatt Place',
            'city' => 'Dallas',
        ]));

        $found = $this->properties->findByNameCity($userId, 'hyatt place', 'dallas');
        self::assertNotNull($found);
        self::assertSame($first->id, $found->id);

        $otherCity = $this->properties->findByNameCity($userId, 'Hyatt Place', 'Houston');
        self::assertNull($otherCity);
    }

    public function testStayRatingsRecomputeOverallAverage(): void
    {
        $userId = $this->insertUser('dave');
        $property = $this->properties->create($this->makeProperty($userId));
        $propertyId = (int) $property->id;

        $this->stays->create($this->makeStay($userId, $propertyId, ['stayRating' => 4]));
        $this->stays->create($this->makeStay($userId, $propertyId, [
            'stayStart' => '2026-09-01',
            'stayEnd' => '2026-09-02',
            'stayRating' => 2,
        ]));

        $updated = $this->properties->find($propertyId);
        self::assertNotNull($updated);
        self::assertSame(3.0, $updated->overallRating);
    }

    public function testUpdateStayRatingUpdatesOverall(): void
    {
        $userId = $this->insertUser('dave');
        $property = $this->properties->create($this->makeProperty($userId));
        $created = $this->stays->create($this->makeStay($userId, (int) $property->id, ['stayRating' => 5]));

        // toArray()/fromRow() use snake_case DB column names; merge overrides
        // in that shape rather than the constructor's camelCase parameter names.
        $updated = HotelStay::fromRow(array_merge($created->toArray(), [
            'stay_rating' => 1,
            'notes' => 'Downgraded on second visit',
        ]));
        $result = $this->stays->update($updated);

        self::assertSame(1, $result->stayRating);
        self::assertSame('Downgraded on second visit', $result->notes);

        $propertyAfter = $this->properties->find((int) $property->id);
        self::assertNotNull($propertyAfter);
        self::assertSame(1.0, $propertyAfter->overallRating);
    }

    public function testValidationRejectsEndBeforeStart(): void
    {
        $userId = $this->insertUser('dave');
        $property = $this->properties->create($this->makeProperty($userId));

        $this->expectException(\InvalidArgumentException::class);
        $this->stays->create($this->makeStay($userId, (int) $property->id, [
            'stayStart' => '2026-08-05',
            'stayEnd' => '2026-08-01',
        ]));
    }

    public function testValidationRejectsInvalidBedType(): void
    {
        $userId = $this->insertUser('dave');
        $property = $this->properties->create($this->makeProperty($userId));

        $this->expectException(\InvalidArgumentException::class);
        $this->stays->create($this->makeStay($userId, (int) $property->id, ['bedType' => 'twin']));
    }

    public function testValidationRequiresBlacklistReasonWhenBlacklisted(): void
    {
        $userId = $this->insertUser('dave');

        $this->expectException(\InvalidArgumentException::class);
        $this->properties->create($this->makeProperty($userId, [
            'isBlacklisted' => true,
            'blacklistReason' => null,
        ]));
    }

    public function testFindMatchingBlacklistIsCaseInsensitive(): void
    {
        $userId = $this->insertUser('dave');
        $this->properties->create($this->makeProperty($userId, [
            'hotelName' => 'Bad Hotel',
            'city' => 'Dallas',
            'isBlacklisted' => true,
            'blacklistReason' => 'Roaches',
        ]));

        $match = $this->properties->findMatchingBlacklist($userId, 'bad hotel', 'dallas');
        self::assertNotNull($match);
        self::assertSame('Roaches', $match->blacklistReason);

        $noMatch = $this->properties->findMatchingBlacklist($userId, 'bad hotel', 'Houston');
        self::assertNull($noMatch);
    }

    public function testNewAmenityFlagsPersist(): void
    {
        $userId = $this->insertUser('dave');
        $created = $this->properties->create($this->makeProperty($userId, [
            'hasEvCharging' => true,
            'hasOnsiteRestaurant' => true,
            'hasOffsiteGym' => true,
            'walkToOffice' => true,
            'walkToOfficeNotes' => 'NewsNation bureau',
            'phone' => '312-555-0100',
        ]));

        $found = $this->properties->find((int) $created->id);
        self::assertNotNull($found);
        self::assertTrue($found->hasEvCharging);
        self::assertTrue($found->hasOnsiteRestaurant);
        self::assertTrue($found->hasOffsiteGym);
        self::assertTrue($found->walkToOffice);
        self::assertSame('NewsNation bureau', $found->walkToOfficeNotes);
        self::assertSame('312-555-0100', $found->phone);
    }

    public function testLocationsForUserAndFindAtLocation(): void
    {
        $userId = $this->insertUser('dave');
        $this->properties->create($this->makeProperty($userId, [
            'hotelName' => 'Hyatt Chicago',
            'city' => 'Chicago',
            'stateRegion' => 'IL',
        ]));
        $this->properties->create($this->makeProperty($userId, [
            'hotelName' => 'Marriott Chicago',
            'city' => 'Chicago',
            'stateRegion' => 'IL',
        ]));
        $this->properties->create($this->makeProperty($userId, [
            'hotelName' => 'Hilton Dallas',
            'city' => 'Dallas',
            'stateRegion' => 'TX',
        ]));

        $locations = $this->properties->locationsForUser($userId);
        self::assertCount(2, $locations);
        $labels = array_column($locations, 'label');
        self::assertContains('Chicago, IL', $labels);
        self::assertContains('Dallas, TX', $labels);

        $chicago = $this->properties->findForUserAtLocation($userId, 'Chicago', 'IL');
        self::assertCount(2, $chicago);
    }

    public function testDestinationFeeAndSearchFilters(): void
    {
        $userId = $this->insertUser('dave');
        $this->properties->create($this->makeProperty($userId, [
            'hotelName' => 'Fee Hotel',
            'city' => 'Austin',
            'stateRegion' => 'TX',
            'hasDestinationFee' => true,
            'destinationFeeNotes' => '$40/night',
        ]));
        $this->properties->create($this->makeProperty($userId, [
            'hotelName' => 'No Fee Inn',
            'city' => 'Austin',
            'stateRegion' => 'TX',
            'hasDestinationFee' => false,
        ]));

        $withFee = $this->properties->searchForUser($userId, ['destination_fee' => '1', 'city' => 'Austin']);
        self::assertCount(1, $withFee);
        self::assertSame('Fee Hotel', $withFee[0]->hotelName);
        self::assertSame('$40/night', $withFee[0]->destinationFeeNotes);
    }

    public function testTeammateAdversePreferencesAreVisible(): void
    {
        $dave = $this->insertUser('dave');
        $sara = $this->insertUser('sara');
        $this->properties->create($this->makeProperty($sara, [
            'hotelName' => 'Shared Name Hotel',
            'city' => 'Nashville',
            'isBlacklisted' => true,
            'blacklistReason' => 'Mold',
        ]));
        $this->properties->create($this->makeProperty($dave, [
            'hotelName' => 'Shared Name Hotel',
            'city' => 'Nashville',
        ]));

        $adverse = $this->properties->findTeammateAdversePreferences($dave, 'Shared Name Hotel', 'Nashville');
        self::assertCount(1, $adverse);
        self::assertSame('Sara', $adverse[0]['display_name']);
        self::assertSame('Mold', $adverse[0]['reason']);
    }

    public function testDeleteRemovesRowAndClearsOverallRating(): void
    {
        $userId = $this->insertUser('dave');
        $property = $this->properties->create($this->makeProperty($userId));
        $created = $this->stays->create($this->makeStay($userId, (int) $property->id, ['stayRating' => 5]));

        $this->stays->delete($created->id);

        self::assertNull($this->stays->find($created->id));
        $propertyAfter = $this->properties->find((int) $property->id);
        self::assertNotNull($propertyAfter);
        self::assertNull($propertyAfter->overallRating);
    }
}
