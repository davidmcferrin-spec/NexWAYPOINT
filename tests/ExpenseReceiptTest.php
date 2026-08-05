<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Receipts\EmailReceiptPdfBuilder;
use NexWaypoint\Receipts\ExpenseReceipt;
use NexWaypoint\Receipts\ExpenseReceiptRepository;
use NexWaypoint\Receipts\ReceiptCaptureService;
use NexWaypoint\Receipts\ReceiptFileStore;
use NexWaypoint\Receipts\SimplePdf;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

final class ExpenseReceiptTest extends NexWaypointTestCase
{
    private string $receiptDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiptDir = sys_get_temp_dir() . '/nx_receipts_' . bin2hex(random_bytes(4));
        mkdir($this->receiptDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->receiptDir)) {
            foreach (glob($this->receiptDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->receiptDir);
        }
        parent::tearDown();
    }

    public function testSimplePdfStartsWithHeader(): void
    {
        $pdf = (new SimplePdf())
            ->title('Vendor confirmation email')
            ->heading('Message')
            ->line('Confirmation ABCXYZ')
            ->render();

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        self::assertStringContainsString('Vendor confirmation', $pdf);
    }

    public function testCapturePrefersVendorPdfAttachment(): void
    {
        $userId = $this->insertUser('receipt_attach');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $day = new \DateTimeImmutable('2026-09-12');
        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: $day->format('Y-m-d'),
            endDate: $day->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'AA',
            flightNumber: '100',
            confirmationCode: 'PDF1',
            origin: 'HSV',
            destination: 'ORD',
            departDt: $day->setTime(7, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(9, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $vendorPdf = "%PDF-1.4\nvendor receipt\n%%EOF\n";
        $message = new EmailMessage(
            uid: 'att-1',
            fromAddress: 'noreply@aa.com',
            subject: 'Your trip confirmation',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: 'Thanks for flying — ignore this, attachment wins',
            bodyHtml: '',
            recipientAddresses: [],
            attachments: [[
                'filename' => 'eticket.pdf',
                'mime_type' => 'application/pdf',
                'content' => $vendorPdf,
            ]],
        );

        $capture = $this->captureService($tripRepo);
        $receipt = $capture->captureFromImport(
            $message,
            $userId,
            'flight',
            (int) $trip->id,
            null,
            42,
            $userId,
        );

        self::assertNotNull($receipt);
        self::assertSame(ExpenseReceipt::SOURCE_ATTACHMENT, $receipt->source);
        self::assertSame('eticket.pdf', $receipt->originalFilename);
        $absolute = (new ReceiptFileStore($this->receiptDir, $this->logger))->absolutePath($receipt->filePath);
        self::assertSame($vendorPdf, file_get_contents((string) $absolute));
    }

    public function testCaptureFallsBackToVendorEmailPdf(): void
    {
        $userId = $this->insertUser('receipt_email');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $day = new \DateTimeImmutable('2026-09-10');
        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver, CO',
            startDate: $day->format('Y-m-d'),
            endDate: $day->modify('+2 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'United',
            flightNumber: '1234',
            confirmationCode: 'ABCXYZ',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $day->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $day->setTime(10, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $message = new EmailMessage(
            uid: 'body-1',
            fromAddress: 'noreply@united.com',
            subject: 'Your United itinerary confirmation',
            receivedAt: new \DateTimeImmutable('2026-09-01 12:00:00'),
            bodyPlain: "Confirmation: ABCXYZ\nTotal charged: \$412.80\nHSV to DEN on UA 1234",
            bodyHtml: '',
            recipientAddresses: [],
            attachments: [],
        );

        $capture = $this->captureService($tripRepo);
        $receipt = $capture->captureFromImport(
            $message,
            $userId,
            'flight',
            (int) $trip->id,
            null,
            7,
            $userId,
        );

        self::assertNotNull($receipt);
        self::assertSame(ExpenseReceipt::SOURCE_EMAIL_BODY, $receipt->source);
        self::assertSame(ExpenseReceipt::KIND_FLIGHT, $receipt->kind);
        self::assertSame('Denver, CO', $receipt->locationLabel);
        self::assertSame('United', $receipt->brand);
        self::assertSame('ABCXYZ', $receipt->confirmationCode);
        self::assertSame('application/pdf', $receipt->mimeType);
        self::assertStringContainsString('vendor email', $receipt->title);

        $absolute = (new ReceiptFileStore($this->receiptDir, $this->logger))->absolutePath($receipt->filePath);
        self::assertNotNull($absolute);
        $bytes = file_get_contents($absolute);
        self::assertIsString($bytes);
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringContainsString('ABCXYZ', $bytes);
        self::assertStringContainsString('412.80', $bytes);
        self::assertStringNotContainsString('not a vendor folio', $bytes);
    }

    public function testCaptureFromHotelEmailUsesStayMeta(): void
    {
        $userId = $this->insertUser('receipt_hotel');
        $props = new HotelPropertyRepository($this->db, $this->logger);
        $property = $props->findOrCreate('Test Hilton', 'Huntsville', 'AL', $userId, $userId, 'Hilton', null, null);
        $stays = new HotelStayRepository($this->db, $this->logger, $props);
        $stay = $stays->create(new HotelStay(
            id: null,
            userId: $userId,
            hotelPropertyId: (int) $property->id,
            roomNumber: null,
            bedType: null,
            bathroomType: null,
            stayStart: '2026-09-20',
            stayEnd: '2026-09-22',
            stayRating: null,
            lastStayPrice: 189.50,
            currency: 'USD',
            bookingSource: 'email',
            confirmationCode: 'HIL123',
            wouldReturn: null,
            notes: null,
        ), $userId);

        $message = new EmailMessage(
            uid: 'hotel-1',
            fromAddress: 'hiltonhonors@hilton.com',
            subject: 'Your Hilton reservation is confirmed',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: "Confirmation Number: HIL123\nTotal for Stay: \$189.50 USD\nCheck-in: Sep 20",
            bodyHtml: '',
        );

        $tripRepo = new TripRepository($this->db, $this->logger);
        $capture = $this->captureService($tripRepo, $stays, $props);
        $receipt = $capture->captureFromImport(
            $message,
            $userId,
            'hotel',
            null,
            (int) $stay->id,
            9,
            $userId,
        );

        self::assertNotNull($receipt);
        self::assertSame(ExpenseReceipt::SOURCE_EMAIL_BODY, $receipt->source);
        self::assertSame(ExpenseReceipt::KIND_HOTEL, $receipt->kind);
        self::assertSame('Hilton', $receipt->brand);
        self::assertSame('HIL123', $receipt->confirmationCode);
        self::assertSame(189.50, $receipt->amount);
        self::assertStringContainsString('Huntsville', $receipt->locationLabel);
    }

    public function testCaptureSkipsWhenNoAttachmentAndNoBody(): void
    {
        $userId = $this->insertUser('receipt_empty');
        $message = new EmailMessage(
            uid: 'empty-1',
            fromAddress: 'noreply@aa.com',
            subject: 'Empty',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: '   ',
            bodyHtml: '',
        );

        $capture = $this->captureService(new TripRepository($this->db, $this->logger));
        $receipt = $capture->captureFromImport($message, $userId, 'flight', null, null, null, $userId);
        self::assertNull($receipt);
    }

    public function testPurgeExpiredRemovesFileAndRow(): void
    {
        $userId = $this->insertUser('receipt_purge');
        $repo = new ExpenseReceiptRepository($this->db, $this->logger);
        $store = new ReceiptFileStore($this->receiptDir, $this->logger);
        $stored = $store->writeBytes("%PDF-1.4\ngone\n%%EOF\n", 'pdf');

        $receipt = $repo->create(new ExpenseReceipt(
            id: null,
            ownerUserId: $userId,
            kind: ExpenseReceipt::KIND_OTHER,
            brand: null,
            locationLabel: 'Somewhere',
            travelDate: '2026-01-01',
            travelEndDate: null,
            confirmationCode: null,
            amount: null,
            currency: null,
            tripId: null,
            hotelStayId: null,
            parseLogId: null,
            source: ExpenseReceipt::SOURCE_ATTACHMENT,
            title: 'Old receipt',
            originalFilename: 'old.pdf',
            mimeType: 'application/pdf',
            filePath: $stored['file_path'],
            fileSize: $stored['file_size'],
            expiresAt: '2020-01-01 00:00:00',
        ), $userId);

        $capture = $this->captureService(new TripRepository($this->db, $this->logger));
        $n = $capture->purgeExpired(new \DateTimeImmutable('2026-08-04 12:00:00'));
        self::assertSame(1, $n);
        self::assertNull($repo->find((int) $receipt->id));
        self::assertNull($store->absolutePath($stored['file_path']));
    }

    private function captureService(
        TripRepository $tripRepo,
        ?HotelStayRepository $stays = null,
        ?HotelPropertyRepository $props = null,
    ): ReceiptCaptureService {
        $props ??= new HotelPropertyRepository($this->db, $this->logger);
        $stays ??= new HotelStayRepository($this->db, $this->logger, $props);
        return new ReceiptCaptureService(
            new ExpenseReceiptRepository($this->db, $this->logger),
            new ReceiptFileStore($this->receiptDir, $this->logger),
            new EmailReceiptPdfBuilder(),
            $tripRepo,
            $stays,
            $props,
            $this->logger,
            90,
        );
    }
}
