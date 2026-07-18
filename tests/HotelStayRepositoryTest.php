<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;

final class HotelStayRepositoryTest extends NexWaypointTestCase
{
    private function makeStay(int $userId, array $overrides = []): HotelStay
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
            'latitude' => null,
            'longitude' => null,
            'roomNumber' => '412',
            'stayStart' => '2026-08-01',
            'stayEnd' => '2026-08-03',
            'rating' => 4,
            'hasDesk' => true,
            'deskNotes' => null,
            'hasPool' => false,
            'hasHotTub' => false,
            'hasBreakfast' => true,
            'breakfastNotes' => null,
            'hasGym' => true,
            'hasFreeParking' => false,
            'hasAirportShuttle' => true,
            'wifiQuality' => 5,
            'noiseLevel' => 2,
            'uniqueFeatures' => null,
            'isBlacklisted' => false,
            'blacklistReason' => null,
            'lastStayPrice' => 189.50,
            'currency' => 'USD',
            'bookingSource' => null,
            'confirmationCode' => 'ABC123',
            'wouldReturn' => true,
            'notes' => null,
        ];
        $merged = array_merge($defaults, $overrides);
        return new HotelStay(...$merged);
    }

    public function testCreateAndFind(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new HotelStayRepository($this->db, $this->logger);

        $created = $repo->create($this->makeStay($userId));

        self::assertNotNull($created->id);
        $found = $repo->find($created->id);
        self::assertNotNull($found);
        self::assertSame('Test Hotel Downtown', $found->hotelName);
        self::assertTrue($found->hasDesk);
        self::assertSame(4, $found->rating);
    }

    public function testFindForUserOrdersByStayStartDesc(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new HotelStayRepository($this->db, $this->logger);

        $repo->create($this->makeStay($userId, ['hotelName' => 'Older Stay', 'stayStart' => '2026-01-01', 'stayEnd' => '2026-01-02']));
        $repo->create($this->makeStay($userId, ['hotelName' => 'Newer Stay', 'stayStart' => '2026-06-01', 'stayEnd' => '2026-06-02']));

        $stays = $repo->findForUser($userId);
        self::assertCount(2, $stays);
        self::assertSame('Newer Stay', $stays[0]->hotelName);
    }

    public function testValidationRejectsEndBeforeStart(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new HotelStayRepository($this->db, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $repo->create($this->makeStay($userId, ['stayStart' => '2026-08-05', 'stayEnd' => '2026-08-01']));
    }

    public function testValidationRequiresBlacklistReasonWhenBlacklisted(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new HotelStayRepository($this->db, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $repo->create($this->makeStay($userId, ['isBlacklisted' => true, 'blacklistReason' => null]));
    }

    public function testFindMatchingBlacklistIsCaseInsensitive(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new HotelStayRepository($this->db, $this->logger);

        $repo->create($this->makeStay($userId, [
            'hotelName' => 'Bad Hotel',
            'city' => 'Dallas',
            'isBlacklisted' => true,
            'blacklistReason' => 'Roaches',
        ]));

        $match = $repo->findMatchingBlacklist($userId, 'bad hotel', 'dallas');
        self::assertNotNull($match);
        self::assertSame('Roaches', $match->blacklistReason);

        $noMatch = $repo->findMatchingBlacklist($userId, 'bad hotel', 'Houston');
        self::assertNull($noMatch);
    }

    public function testUpdateChangesFields(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new HotelStayRepository($this->db, $this->logger);

        $created = $repo->create($this->makeStay($userId));
        // toArray()/fromRow() use snake_case DB column names; merge overrides
        // in that shape rather than the constructor's camelCase parameter names.
        $updated = HotelStay::fromRow(array_merge($created->toArray(), ['rating' => 2, 'notes' => 'Downgraded on second visit']));
        $result = $repo->update($updated);

        self::assertSame(2, $result->rating);
        self::assertSame('Downgraded on second visit', $result->notes);
    }

    public function testDeleteRemovesRow(): void
    {
        $userId = $this->insertUser('dave');
        $repo = new HotelStayRepository($this->db, $this->logger);

        $created = $repo->create($this->makeStay($userId));
        $repo->delete($created->id);

        self::assertNull($repo->find($created->id));
    }
}
