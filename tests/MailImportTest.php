<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailConfirmationDetector;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\MailPoller;
use NexWaypoint\Mail\MailSourceInterface;
use NexWaypoint\Mail\NullMailSource;
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

        $checkIn = $detector->detect($this->msg(
            'no-reply@info.email.aa.com',
            'Check in for your AA flight to Dallas'
        ));
        self::assertSame('flight', $checkIn['type']);
        self::assertSame('ignore', $checkIn['event']);

        $fwdCheckIn = $detector->detect($this->msg(
            'dave@example.com',
            'Fw: It\'s time to check in'
        ));
        // Without vendor in From, type may be unknown unless body hints — subject alone.
        self::assertTrue(EmailConfirmationDetector::isAirlineCheckInSubject('Fw: It\'s time to check in'));
        self::assertTrue(EmailConfirmationDetector::isAirlineCheckInSubject('Your boarding pass is ready'));
        self::assertFalse(EmailConfirmationDetector::isAirlineCheckInSubject('Your trip confirmation'));
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

    public function testPastItineraryImportMarkedCompleted(): void
    {
        $userId = $this->insertUser('dave');
        $trips = new TripRepository($this->db, $this->logger);
        $carriers = new CarrierRepository($this->db, $this->logger);
        $carrier = $carriers->findOrCreateByIata($userId, 'AA', 'American Airlines', $userId);

        $result = $trips->upsertItineraryByConfirmation($userId, 'OLDPNR', [[
            'segment_type' => 'flight',
            'carrier_id' => $carrier->id,
            'carrier' => $carrier->name,
            'flight_number' => '50',
            'origin' => 'ORD',
            'destination' => 'DFW',
            'depart_dt' => '2024-03-01 10:00:00',
            'arrive_dt' => '2024-03-01 12:30:00',
        ]], null, $userId);

        self::assertTrue($result['created']);
        self::assertSame('completed', $result['trip']->status);
        self::assertSame('completed', $result['segments'][0]->status);
        self::assertSame('2024-03-01', $result['trip']->endDate);
    }

    public function testFutureItineraryImportStaysPlanned(): void
    {
        $userId = $this->insertUser('dave');
        $trips = new TripRepository($this->db, $this->logger);
        $carriers = new CarrierRepository($this->db, $this->logger);
        $carrier = $carriers->findOrCreateByIata($userId, 'AA', 'American Airlines', $userId);

        $result = $trips->upsertItineraryByConfirmation($userId, 'NEWPNR', [[
            'segment_type' => 'flight',
            'carrier_id' => $carrier->id,
            'carrier' => $carrier->name,
            'flight_number' => '100',
            'origin' => 'ORD',
            'destination' => 'DFW',
            'depart_dt' => '2099-09-01 10:00:00',
            'arrive_dt' => '2099-09-01 12:00:00',
        ]], null, $userId);

        self::assertSame('planned', $result['trip']->status);
        self::assertSame('scheduled', $result['segments'][0]->status);
    }

    public function testHotelUpsertAndCancelByConfirmation(): void
    {
        $userId = $this->insertUser('dave');
        $props = new HotelPropertyRepository($this->db, $this->logger);
        $stays = new HotelStayRepository($this->db, $this->logger, $props);

        $property = $props->create(new \NexWaypoint\Hotels\HotelProperty(
            id: null,
            createdByUserId: $userId,
            hotelName: 'Test Hilton Downtown',
            brand: 'Hilton',
            addressLine1: null,
            addressLine2: null,
            city: 'Chicago',
            stateRegion: 'IL',
            postalCode: null,
            country: null,
            phone: null,
            website: null,
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

    public function testMailPollerIgnoresAirlineCheckInAndDoesNotShrinkItinerary(): void
    {
        $userId = $this->insertUser('dave');
        $trips = new TripRepository($this->db, $this->logger);
        $carriers = new CarrierRepository($this->db, $this->logger);
        $carrier = $carriers->findOrCreateByIata($userId, 'AA', 'American Airlines', $userId);

        $trips->upsertItineraryByConfirmation($userId, 'CHKIN1', [
            [
                'segment_type' => 'flight',
                'carrier_id' => $carrier->id,
                'carrier' => $carrier->name,
                'flight_number' => '100',
                'origin' => 'HSV',
                'destination' => 'DFW',
                'depart_dt' => '2026-09-01 08:00:00',
                'arrive_dt' => '2026-09-01 10:00:00',
            ],
            [
                'segment_type' => 'flight',
                'carrier_id' => $carrier->id,
                'carrier' => $carrier->name,
                'flight_number' => '200',
                'origin' => 'DFW',
                'destination' => 'HSV',
                'depart_dt' => '2026-09-05 12:00:00',
                'arrive_dt' => '2026-09-05 14:00:00',
            ],
        ], null, $userId);

        self::assertCount(2, $trips->findSegmentsByConfirmation($userId, 'CHKIN1'));

        // Check-in subject must be ignored before replace.
        $checkInSource = new ArrayMailSource([
            new EmailMessage(
                uid: 'checkin-1',
                fromAddress: 'no-reply@info.email.aa.com',
                subject: 'Check in for your AA flight',
                receivedAt: new \DateTimeImmutable('2026-08-30'),
                bodyPlain: "Confirmation code: CHKIN1\nHSV\nAA 100\nDFW\n",
                bodyHtml: '',
            ),
        ]);

        $props = new HotelPropertyRepository($this->db, $this->logger);
        $poller = new MailPoller(
            $checkInSource,
            'test',
            new EmailConfirmationDetector(),
            new UserRepository($this->db, $this->logger),
            $props,
            new HotelStayRepository($this->db, $this->logger, $props),
            $trips,
            $carriers,
            new NotificationRepository($this->db),
            new ParseLogRepository($this->db),
            $this->logger,
        );

        $result = $poller->run();
        self::assertSame(1, $result['success']);
        self::assertSame(0, $result['failed']);
        self::assertCount(2, $trips->findSegmentsByConfirmation($userId, 'CHKIN1'));

        // Defense in depth: even a confirm-shaped message with only one leg must not shrink.
        $thinConfirm = new ArrayMailSource([
            new EmailMessage(
                uid: 'thin-1',
                fromAddress: 'no-reply@info.email.aa.com',
                subject: 'Your trip confirmation',
                receivedAt: new \DateTimeImmutable('2026-08-31'),
                bodyPlain: "Confirmation code: CHKIN1",
                bodyHtml: <<<'HTML'
<script type="application/ld+json">
{
  "@type": "FlightReservation",
  "reservationNumber": "CHKIN1",
  "reservationFor": {
    "@type": "Flight",
    "flightNumber": "AA 100",
    "airline": {"iataCode": "AA", "name": "American Airlines"},
    "departureAirport": {"iataCode": "HSV"},
    "arrivalAirport": {"iataCode": "DFW"},
    "departureTime": "2026-09-01T08:00:00",
    "arrivalTime": "2026-09-01T10:00:00"
  }
}
</script>
HTML,
            ),
        ]);

        $poller2 = new MailPoller(
            $thinConfirm,
            'test',
            new EmailConfirmationDetector(),
            new UserRepository($this->db, $this->logger),
            $props,
            new HotelStayRepository($this->db, $this->logger, $props),
            $trips,
            $carriers,
            new NotificationRepository($this->db),
            new ParseLogRepository($this->db),
            $this->logger,
        );
        $result2 = $poller2->run();
        self::assertSame(1, $result2['success']);
        $segments = $trips->findSegmentsByConfirmation($userId, 'CHKIN1');
        self::assertCount(2, $segments);
        self::assertSame('HSV', $segments[0]->origin);
        self::assertSame('DFW', $segments[0]->destination);
        self::assertSame('DFW', $segments[1]->origin);
        self::assertSame('HSV', $segments[1]->destination);
    }

    public function testMailPollerOwnsVendorFromViaBodyDeliveredTo(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $user = $repo->create('dave', 'dave@work.example', 'test-password-12', 'Dave', 'subordinate', null);
        $repo->addEmail((int) $user->id, 'david.mcferrin@pm.me', 'Proton', (int) $user->id);

        $html = <<<'HTML'
<script type="application/ld+json">
{
  "@type": "FlightReservation",
  "reservationNumber": "HSVDFW1",
  "reservationFor": {
    "@type": "Flight",
    "flightNumber": "AA 100",
    "airline": {"iataCode": "AA", "name": "American Airlines"},
    "departureAirport": {"iataCode": "HSV"},
    "arrivalAirport": {"iataCode": "DFW"},
    "departureTime": "2026-08-20T12:00:00Z",
    "arrivalTime": "2026-08-20T14:00:00Z"
  }
}
</script>
HTML;

        $source = new ArrayMailSource([
            new EmailMessage(
                uid: 'aa-vendor-from',
                fromAddress: 'no-reply@info.email.aa.com',
                subject: 'Your trip confirmation (HSV - DFW)',
                receivedAt: new \DateTimeImmutable('2026-07-31'),
                bodyPlain: "Confirmation code: HSVDFW1\nThis email was sent to david.mcferrin@pm.me",
                bodyHtml: $html,
            ),
        ]);

        $props = new HotelPropertyRepository($this->db, $this->logger);
        $poller = new MailPoller(
            $source,
            'test',
            new EmailConfirmationDetector(),
            $repo,
            $props,
            new HotelStayRepository($this->db, $this->logger, $props),
            new TripRepository($this->db, $this->logger),
            new CarrierRepository($this->db, $this->logger),
            new NotificationRepository($this->db),
            new ParseLogRepository($this->db),
            $this->logger,
        );

        $result = $poller->run();
        self::assertSame(1, $result['success'], 'vendor From should attribute via body recipient');
        self::assertSame(0, $result['failed']);

        $trips = new TripRepository($this->db, $this->logger);
        $segments = $trips->findSegmentsByConfirmation((int) $user->id, 'HSVDFW1');
        self::assertCount(1, $segments);
    }

    /**
     * Re-parse must work for Gmail / Outlook / Proton wrappers and direct vendor mail.
     *
     * @dataProvider reprocessForwardClientProvider
     */
    public function testReprocessMessageFromStoredShapeSucceeds(
        string $uid,
        string $subject,
        string $body,
    ): void {
        $repo = new UserRepository($this->db, $this->logger);
        $user = $repo->create('dave', 'dave@example.com', 'test-password-12', 'Dave', 'subordinate', null);

        $message = new EmailMessage(
            uid: $uid,
            fromAddress: 'dave@example.com',
            subject: $subject,
            receivedAt: new \DateTimeImmutable('2026-07-20'),
            bodyPlain: $body,
            bodyHtml: '',
        );

        $props = new HotelPropertyRepository($this->db, $this->logger);
        $poller = new MailPoller(
            new NullMailSource(),
            'dreamhost_imap',
            new EmailConfirmationDetector(),
            $repo,
            $props,
            new HotelStayRepository($this->db, $this->logger, $props),
            new TripRepository($this->db, $this->logger),
            new CarrierRepository($this->db, $this->logger),
            new NotificationRepository($this->db),
            new ParseLogRepository($this->db),
            $this->logger,
        );

        $first = $poller->reprocessMessage($message);
        self::assertTrue($first['ok'], ($first['reason'] ?? 'expected success') . " [{$uid}]");

        $stays = new HotelStayRepository($this->db, $this->logger, $props);
        $found = $stays->findByConfirmationCode((int) $user->id, '3500303313');
        self::assertNotNull($found, "stay missing after reprocess [{$uid}]");

        // Force reprocess (default) re-upserts by confirmation; still succeeds.
        $second = $poller->reprocessMessage($message);
        self::assertTrue($second['ok']);
        $again = $stays->findByConfirmationCode((int) $user->id, '3500303313');
        self::assertNotNull($again);
        self::assertSame($found->id, $again->id);
    }

    public function testForceReprocessReplacesIncompleteFlightItinerary(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $user = $repo->create('dave', 'dave@example.com', 'test-password-12', 'Dave', 'subordinate', null);
        $userId = (int) $user->id;

        $trips = new TripRepository($this->db, $this->logger);
        $carriers = new CarrierRepository($this->db, $this->logger);
        $carrier = $carriers->findOrCreateByIata($userId, 'AA', 'American Airlines', $userId);

        // Simulate prior incomplete import (outbound only).
        $first = $trips->upsertItineraryByConfirmation($userId, 'NZWPVQ', [[
            'segment_type' => 'flight',
            'carrier_id' => $carrier->id,
            'carrier' => $carrier->name,
            'flight_number' => '3579',
            'origin' => 'HSV',
            'destination' => 'DFW',
            'depart_dt' => '2026-08-03 19:37:00',
            'arrive_dt' => '2026-08-03 21:50:00',
        ]], null, $userId);
        self::assertCount(1, $first['segments']);
        $tripId = (int) $first['trip']->id;

        $parseLog = new ParseLogRepository($this->db);
        $parseLog->record(
            new \DateTimeImmutable('2026-07-31'),
            'dave@example.com',
            'Fw: Your trip confirmation (HSV - DFW)',
            '80',
            'dreamhost_imap',
            'flight',
            'success',
            null,
            1.0,
            $userId,
            $first['segments'][0]->id,
            ['trip_id' => $tripId],
        );

        $body = <<<'TXT'
Sent with Proton Mail secure email.

------- Forwarded Message -------
From: American Airlines <no-reply@info.email.aa.com>
Subject: Your trip confirmation (HSV - DFW)
To: dave@example.com <dave@example.com>

> Confirmation code: NZWPVQ
>
> Monday, August 3, 2026
>
> HSV
>
> Huntsville
>
> 7:37 PM
>
> AA 3579
>
> Operated by Envoy Air as American Eagle
>
> DFW
>
> Dallas/Fort Worth
>
> 9:50 PM
>
> Thursday, August 6, 2026
>
> DFW
>
> Dallas/Fort Worth
>
> 8:00 PM
>
> AA 4317
>
> Operated by Envoy Air as American Eagle
>
> HSV
>
> Huntsville
>
> 9:56 PM
>
> Manage your trip
TXT;

        $message = new EmailMessage(
            uid: '80',
            fromAddress: 'dave@example.com',
            subject: 'Fw: Your trip confirmation (HSV - DFW)',
            receivedAt: new \DateTimeImmutable('2026-07-31'),
            bodyPlain: $body,
            bodyHtml: '',
        );

        $props = new HotelPropertyRepository($this->db, $this->logger);
        $poller = new MailPoller(
            new NullMailSource(),
            'dreamhost_imap',
            new EmailConfirmationDetector(),
            $repo,
            $props,
            new HotelStayRepository($this->db, $this->logger, $props),
            $trips,
            $carriers,
            new NotificationRepository($this->db),
            $parseLog,
            $this->logger,
        );

        // Without force, already-success short-circuits and leaves the incomplete trip.
        $skipped = $poller->reprocessMessage($message, false);
        self::assertTrue($skipped['ok']);
        self::assertCount(1, $trips->findSegmentsByConfirmation($userId, 'NZWPVQ'));

        $forced = $poller->reprocessMessage($message, true);
        self::assertTrue($forced['ok'], $forced['reason'] ?? 'force reprocess failed');

        $segments = $trips->findSegmentsByConfirmation($userId, 'NZWPVQ');
        self::assertCount(2, $segments);
        self::assertSame($tripId, $segments[0]->tripId);
        self::assertSame($tripId, $segments[1]->tripId);
        self::assertSame('3579', $segments[0]->flightNumber);
        self::assertSame('4317', $segments[1]->flightNumber);
        self::assertSame('HSV', $segments[1]->destination);

        $trip = $trips->find($tripId);
        self::assertNotNull($trip);
        self::assertSame('DFW', $trip->destinationCity);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function reprocessForwardClientProvider(): array
    {
        $hiltonBody = <<<'TXT'
> Hello DAVID,
>
> Your reservation for Tuesday Jul 07, 2026 has been confirmed.
>
> Confirmation # 3500303313
>
> New York Hilton Midtown
> -----------------------
>
> 1335 Avenue of the Americas, New York, NY, 10019 US
>
> +12125867000
>
> Tuesday
>
> Jul 07
>
> Check In:  3:00 PM
>
> ### 6
>
> Nights
>
> Monday
>
> Jul 13
>
> Check Out: 12:00 PM
>
> Jul-07-2026 - Jul-09-2026
> Jul-09-2026 - Jul-10-2026
> Jul-10-2026 - Jul-11-2026
> Jul-11-2026 - Jul-12-2026
> Jul-12-2026 - Jul-13-2026
TXT;

        return [
            'proton_forward' => [
                'reparse-proton',
                'Fw: Your Jul-07-2026 Confirmation #3500303313',
                "Sent with Proton Mail secure email.\n\n------- Forwarded Message -------\n"
                . "From: Hilton Hotels & Resorts Confirmed <noreply@h6.hilton.com>\n"
                . "Subject: Your Jul-07-2026 Confirmation #3500303313\n"
                . "To: dave@example.com <dave@example.com>\n\n"
                . $hiltonBody,
            ],
            'gmail_forward' => [
                'reparse-gmail',
                'Fwd: Your Jul-07-2026 Confirmation #3500303313',
                "FYI\n\n---------- Forwarded message ---------\n"
                . "From: Hilton Hotels & Resorts Confirmed <noreply@h6.hilton.com>\n"
                . "Date: Tue, Jul 7, 2026 at 11:34 AM\n"
                . "Subject: Your Jul-07-2026 Confirmation #3500303313\n"
                . "To: <dave@example.com>\n\n"
                . str_replace('> ', '', $hiltonBody),
            ],
            'outlook_forward' => [
                'reparse-outlook',
                'FW: Your Jul-07-2026 Confirmation #3500303313',
                "See below.\n\n-----Original Message-----\n"
                . "From: Hilton Hotels & Resorts Confirmed [mailto:noreply@h6.hilton.com]\n"
                . "Sent: Tuesday, July 7, 2026 11:34 AM\n"
                . "To: dave@example.com\n"
                . "Subject: Your Jul-07-2026 Confirmation #3500303313\n\n"
                . str_replace('> ', '', $hiltonBody),
            ],
        ];
    }

    public function testReprocessMessageReportsFailureWithoutImapSideEffects(): void
    {
        $repo = new UserRepository($this->db, $this->logger);
        $repo->create('dave', 'dave@example.com', 'test-password-12', 'Dave', 'subordinate', null);

        $message = new EmailMessage(
            uid: '778',
            fromAddress: 'dave@example.com',
            subject: 'Random newsletter',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: 'Nothing to parse here',
            bodyHtml: '',
        );

        $props = new HotelPropertyRepository($this->db, $this->logger);
        $poller = new MailPoller(
            new NullMailSource(),
            'dreamhost_imap',
            new EmailConfirmationDetector(),
            $repo,
            $props,
            new HotelStayRepository($this->db, $this->logger, $props),
            new TripRepository($this->db, $this->logger),
            new CarrierRepository($this->db, $this->logger),
            new NotificationRepository($this->db),
            new ParseLogRepository($this->db),
            $this->logger,
        );

        $result = $poller->reprocessMessage($message);
        self::assertFalse($result['ok']);
        self::assertNotNull($result['reason']);
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
