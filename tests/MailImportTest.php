<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\MailPoller;
use NexWaypoint\Mail\MailSourceInterface;
use NexWaypoint\Mail\ParseLogRepository;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Users\UserRepository;

final class MailImportTest extends NexWaypointTestCase
{
    public function testDetectorSuffixAndEvents(): void
    {
        $detector = new EmailConfirmationDetector();

        $aa = $detector->detect($this->msg('no-reply@info.email.aa.com', 'Your trip confirmation'));
        self::assertSame('flight', $aa['type']);
        self::assertSame('confirm', $aa['event']);

        $hilton = $detector->detect($this->msg('reservations@h6.hilton.com', 'Your Jul-01-2026 Confirmation'));
        self::assertSame('hotel', $hilton['type']);

        $cancel = $detector->detect($this->msg('no-reply@info.email.aa.com', 'Your trip has been cancelled'));
        self::assertSame('cancel', $cancel['event']);

        $folio = $detector->detect($this->msg('noreply@marriott.com', 'Thank you for your stay at Courtyard'));
        self::assertSame('ignore', $folio['event']);

        $forwarded = $detector->detect(new EmailMessage(
            uid: 'f1',
            fromAddress: 'dave@example.com',
            subject: 'Fwd: Your trip confirmation',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: "Forwarded message\nFrom: American Airlines <no-reply@info.email.aa.com>\n",
            bodyHtml: '',
        ));
        self::assertSame('flight', $forwarded['type']);
        self::assertSame('aa.com', $forwarded['matched_domain']);
    }

    public function testTripUpsertAndCancelByPnr(): void
    {
        $userId = $this->insertUser('dave');
        $trips = new TripRepository($this->db, $this->logger);
        $carriers = new CarrierRepository($this->db, $this->logger);
        $carrier = $carriers->findOrCreateByIata($userId, 'AA', 'American Airlines', $userId);

        $first = $trips->upsertItineraryByConfirmation($userId, 'PNR001', [[
            'segment_type' => 'flight',
            'carrier_id' => $carrier->id,
            'carrier' => $carrier->name,
            'flight_number' => '100',
            'origin' => 'ORD',
            'destination' => 'DFW',
            'depart_dt' => '2026-09-01 10:00:00',
            'arrive_dt' => '2026-09-01 12:00:00',
        ]], null, $userId);

        self::assertTrue($first['created']);
        self::assertCount(1, $first['segments']);

        $second = $trips->upsertItineraryByConfirmation($userId, 'PNR001', [
            [
                'segment_type' => 'flight',
                'carrier_id' => $carrier->id,
                'carrier' => $carrier->name,
                'flight_number' => '200',
                'origin' => 'ORD',
                'destination' => 'CLT',
                'depart_dt' => '2026-09-02 08:00:00',
                'arrive_dt' => null,
            ],
            [
                'segment_type' => 'flight',
                'carrier_id' => $carrier->id,
                'carrier' => $carrier->name,
                'flight_number' => '201',
                'origin' => 'CLT',
                'destination' => 'MIA',
                'depart_dt' => '2026-09-02 11:00:00',
                'arrive_dt' => null,
            ],
        ], null, $userId);

        self::assertFalse($second['created']);
        self::assertSame($first['trip']->id, $second['trip']->id);
        self::assertCount(2, $second['segments']);
        self::assertSame('MIA', $second['trip']->destinationCity);

        $cancelled = $trips->cancelByConfirmation($userId, 'PNR001', $userId);
        self::assertSame(2, $cancelled);
        $trip = $trips->find((int) $second['trip']->id);
        self::assertNotNull($trip);
        self::assertSame('cancelled', $trip->status);
    }

    public function testHotelUpsertAndCancelByConfirmation(): void
    {
        $userId = $this->insertUser('dave');
        $props = new HotelPropertyRepository($this->db, $this->logger);
        $stays = new HotelStayRepository($this->db, $this->logger, $props);

        $property = $props->create(new \NexWaypoint\Hotels\HotelProperty(
            id: null,
            userId: $userId,
            hotelName: 'Test Hilton Downtown',
            brand: 'Hilton',
            addressLine1: null,
            addressLine2: null,
            city: 'Chicago',
            stateRegion: 'IL',
            postalCode: null,
            country: null,
            phone: null,
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
            hasDestinationFee: false,
            destinationFeeNotes: null,
            wifiQuality: null,
            noiseLevel: null,
            uniqueFeatures: null,
            isBlacklisted: false,
            blacklistReason: null,
        ), $userId);

        $stay = new \NexWaypoint\Hotels\HotelStay(
            id: null,
            userId: $userId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: '2026-07-09',
            stayEnd: '2026-07-12',
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: 'email_import',
            confirmationCode: '3473619285',
            wouldReturn: null,
            notes: 'import',
        );

        $created = $stays->upsertFromImport($stay, $userId);
        self::assertTrue($created['created']);

        $updated = $stays->upsertFromImport(new \NexWaypoint\Hotels\HotelStay(
            id: null,
            userId: $userId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: '2026-07-10',
            stayEnd: '2026-07-13',
            stayRating: null,
            lastStayPrice: null,
            currency: 'USD',
            bookingSource: 'email_import',
            confirmationCode: '3473619285',
            wouldReturn: null,
            notes: 'import',
        ), $userId);

        self::assertFalse($updated['created']);
        self::assertSame($created['stay']->id, $updated['stay']->id);
        self::assertSame('2026-07-10', $updated['stay']->stayStart);

        // Hilton-style cancel: different cancellation #, match by name+dates
        $cancelled = $stays->cancelFromImport(
            $userId,
            '1930402064',
            'Test Hilton Downtown',
            '2026-07-10',
            '2026-07-13',
            $userId,
        );
        self::assertNotNull($cancelled);
        self::assertNull($stays->find((int) $cancelled->id));
    }

    public function testMailPollerImportsAaConfirmation(): void
    {
        $userId = $this->insertUser('dave');

        $html = <<<'HTML'
<script type="application/ld+json">
{
  "@type": "FlightReservation",
  "reservationNumber": "OEVQZC",
  "reservationFor": {
    "@type": "Flight",
    "flightNumber": "AA 5213",
    "airline": {"iataCode": "AA", "name": "American Airlines"},
    "departureAirport": {"iataCode": "HSV"},
    "arrivalAirport": {"iataCode": "CLT"},
    "departureTime": "2026-08-15T06:00:00Z",
    "arrivalTime": "2026-08-15T08:30:00Z"
  }
}
</script>
HTML;

        // Realistic forward: From is the teammate; body still identifies AA.
        $source = new ArrayMailSource([
            new EmailMessage(
                uid: 'aa-1',
                fromAddress: 'dave@example.com',
                subject: 'Your trip confirmation',
                receivedAt: new \DateTimeImmutable('2026-07-01'),
                bodyPlain: "---------- Forwarded message ----------\nFrom: American Airlines <no-reply@info.email.aa.com>\nConfirmation code: OEVQZC",
                bodyHtml: $html,
            ),
        ]);

        $props = new HotelPropertyRepository($this->db, $this->logger);
        $poller = new MailPoller(
            $source,
            'test',
            new EmailConfirmationDetector(),
            new UserRepository($this->db, $this->logger),
            $props,
            new HotelStayRepository($this->db, $this->logger, $props),
            new TripRepository($this->db, $this->logger),
            new CarrierRepository($this->db, $this->logger),
            new NotificationRepository($this->db),
            new ParseLogRepository($this->db),
            $this->logger,
        );

        $result = $poller->run();
        self::assertSame(1, $result['success']);
        self::assertSame(0, $result['failed']);

        $trips = new TripRepository($this->db, $this->logger);
        $segments = $trips->findSegmentsByConfirmation($userId, 'OEVQZC');
        self::assertCount(1, $segments);
        self::assertSame('HSV', $segments[0]->origin);
        self::assertSame('CLT', $segments[0]->destination);
    }

    private function msg(string $from, string $subject): EmailMessage
    {
        return new EmailMessage(
            uid: 'd1',
            fromAddress: $from,
            subject: $subject,
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: 'body',
            bodyHtml: '',
        );
    }
}

/**
 * Minimal in-memory mail source for poller tests.
 */
final class ArrayMailSource implements MailSourceInterface
{
    /** @var list<EmailMessage> */
    private array $messages;
    /** @var list<string> */
    public array $processed = [];
    /** @var list<string> */
    public array $failed = [];

    /**
     * @param list<EmailMessage> $messages
     */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function fetchUnseenMessages(): array
    {
        return $this->messages;
    }

    public function markProcessed(string $uid): void
    {
        $this->processed[] = $uid;
    }

    public function markFailed(string $uid, string $reason): void
    {
        $this->failed[] = $uid . ':' . $reason;
    }

    public function disconnect(): void
    {
    }
}
