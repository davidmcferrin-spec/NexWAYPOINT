<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Receipts\ExpenseReceipt;
use NexWaypoint\Receipts\ExpenseReceiptRepository;
use NexWaypoint\Receipts\ReceiptCaptureService;
use NexWaypoint\Receipts\ReceiptFileStore;
use NexWaypoint\Receipts\ReceiptPdfBuilder;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\TripRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$db = $app['db'];
$logger = $app['logger'];

$schemaWarning = null;
if (!$db->tableExists('expense_receipts')) {
    $schemaWarning = 'Database is missing expense_receipts. On the server run: php scripts/migrate.php';
}

$tripRepo = new TripRepository($db, $logger);
$propertyRepo = new HotelPropertyRepository($db, $logger);
$stayRepo = new HotelStayRepository($db, $logger, $propertyRepo);
$receiptRepo = $schemaWarning === null ? new ExpenseReceiptRepository($db, $logger) : null;
$fileStore = new ReceiptFileStore(NEXWAYPOINT_ROOT . '/storage/receipts', $logger);
$capture = $receiptRepo !== null
    ? new ReceiptCaptureService(
        $receiptRepo,
        $fileStore,
        new ReceiptPdfBuilder($tripRepo, $stayRepo, $propertyRepo, new AirportRepository($db, $logger)),
        $tripRepo,
        $stayRepo,
        $logger,
        ReceiptCaptureService::retentionDaysFromEnv(),
    )
    : null;

$errors = [];
$message = null;

if ($capture !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'generate_trip') {
                $tripId = (int) ($_POST['trip_id'] ?? 0);
                $receipt = $capture->generateForTrip($tripId, $user->id, null, $user->id);
                $message = 'Receipt PDF ready: ' . $receipt->title;
            } elseif ($action === 'generate_stay') {
                $stayId = (int) ($_POST['hotel_stay_id'] ?? 0);
                $receipt = $capture->generateForStay($stayId, $user->id, null, $user->id);
                $message = 'Receipt PDF ready: ' . $receipt->title;
            } elseif ($action === 'upload') {
                $kind = (string) ($_POST['kind'] ?? ExpenseReceipt::KIND_OTHER);
                $location = trim((string) ($_POST['location_label'] ?? ''));
                $travelDate = trim((string) ($_POST['travel_date'] ?? ''));
                $travelEnd = trim((string) ($_POST['travel_end_date'] ?? ''));
                $brand = trim((string) ($_POST['brand'] ?? ''));
                $conf = trim((string) ($_POST['confirmation_code'] ?? ''));
                $amountRaw = trim((string) ($_POST['amount'] ?? ''));
                $tripId = (int) ($_POST['trip_id'] ?? 0);
                $title = trim((string) ($_POST['title'] ?? ''));

                if ($location === '') {
                    throw new RuntimeException('Location is required.');
                }
                if ($travelDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate)) {
                    throw new RuntimeException('Travel date must be YYYY-MM-DD.');
                }
                if ($travelEnd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelEnd)) {
                    throw new RuntimeException('End date must be YYYY-MM-DD.');
                }
                if (!isset($_FILES['receipt_file'])) {
                    throw new RuntimeException('Choose a file to upload.');
                }

                $receipt = $capture->storeUpload(
                    $user->id,
                    $_FILES['receipt_file'],
                    $kind,
                    $location,
                    $travelDate,
                    $travelEnd !== '' ? $travelEnd : null,
                    $brand !== '' ? $brand : null,
                    $conf !== '' ? $conf : null,
                    $amountRaw !== '' ? (float) $amountRaw : null,
                    'USD',
                    $tripId > 0 ? $tripId : null,
                    null,
                    $title !== '' ? $title : null,
                    $user->id,
                );
                $message = 'Uploaded: ' . $receipt->title;
            } elseif ($action === 'delete') {
                $id = (int) ($_POST['receipt_id'] ?? 0);
                $existing = $receiptRepo->findForOwner($id, $user->id);
                if ($existing === null) {
                    throw new RuntimeException('Receipt not found.');
                }
                $fileStore->deleteRelative($existing->filePath);
                $receiptRepo->delete($id, $user->id);
                $message = 'Receipt deleted.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$receipts = $receiptRepo !== null ? $receiptRepo->listForOwner($user->id) : [];
$trips = $tripRepo->searchForOwner($user->id, 'all');
$stays = $stayRepo->findForUser($user->id);

$retentionDays = ReceiptCaptureService::retentionDaysFromEnv();
$sourceLabel = static function (string $source): string {
    return match ($source) {
        ExpenseReceipt::SOURCE_GENERATED => 'Generated',
        ExpenseReceipt::SOURCE_ATTACHMENT => 'Vendor PDF',
        ExpenseReceipt::SOURCE_UPLOAD => 'Upload',
        ExpenseReceipt::SOURCE_EMAIL_BODY => 'Email',
        default => $source,
    };
};

$formatDate = static function (string $date): string {
    try {
        return (new DateTimeImmutable($date))->format('M j, Y');
    } catch (Exception) {
        return $date;
    }
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Receipts</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <div class="card-header-row" style="margin-bottom: 1rem;">
        <div>
            <h1 style="margin: 0;">Expense receipts</h1>
            <p class="hint" style="margin: 0.35rem 0 0;">
                PDFs for expense reports — kept about <?= (int) $retentionDays ?> days.
                Mail imports create one automatically (vendor PDF when attached, otherwise a trip/stay summary).
            </p>
        </div>
    </div>

    <?php if ($schemaWarning !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($schemaWarning, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if ($capture !== null): ?>
        <div class="card" style="margin-bottom: 1.5rem;">
            <h2 style="margin-top: 0;">Upload a receipt</h2>
            <form method="post" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="upload">
                <label>File (PDF / JPG / PNG)
                    <input type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*" required>
                </label>
                <label>Kind
                    <select name="kind">
                        <option value="flight">Flight</option>
                        <option value="train">Train</option>
                        <option value="hotel">Hotel</option>
                        <option value="other" selected>Other</option>
                    </select>
                </label>
                <label>Location / route
                    <input type="text" name="location_label" required placeholder="e.g. HSV → DEN or Chicago, IL">
                </label>
                <label>Brand
                    <input type="text" name="brand" placeholder="Airline / hotel brand">
                </label>
                <label>Travel date
                    <input type="date" name="travel_date" required>
                </label>
                <label>End date (optional)
                    <input type="date" name="travel_end_date">
                </label>
                <label>Confirmation (optional)
                    <input type="text" name="confirmation_code">
                </label>
                <label>Amount (optional)
                    <input type="number" step="0.01" name="amount">
                </label>
                <label>Link to trip (optional)
                    <select name="trip_id">
                        <option value="">—</option>
                        <?php foreach ($trips as $trip): ?>
                            <option value="<?= (int) $trip->id ?>">
                                <?= htmlspecialchars($trip->destinationCity . ' · ' . $trip->startDate, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Title (optional)
                    <input type="text" name="title" placeholder="Short label for the bin">
                </label>
                <div class="row-actions">
                    <button type="submit" class="primary">Upload</button>
                </div>
            </form>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <h2 style="margin-top: 0;">Generate from a trip or stay</h2>
            <form method="post" class="row-actions" style="margin-bottom: 0.75rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="generate_trip">
                <label style="flex: 1; min-width: 12rem;">Trip
                    <select name="trip_id" required>
                        <option value="">Choose trip…</option>
                        <?php foreach ($trips as $trip): ?>
                            <option value="<?= (int) $trip->id ?>">
                                <?= htmlspecialchars($trip->destinationCity . ' · ' . $trip->startDate, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Generate PDF</button>
            </form>
            <form method="post" class="row-actions">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="generate_stay">
                <label style="flex: 1; min-width: 12rem;">Hotel stay
                    <select name="hotel_stay_id" required>
                        <option value="">Choose stay…</option>
                        <?php foreach ($stays as $stay): ?>
                            <?php
                            $prop = $propertyRepo->find($stay->hotelPropertyId);
                            $label = ($prop?->hotelName ?? 'Stay') . ' · ' . $stay->stayStart;
                            ?>
                            <option value="<?= (int) $stay->id ?>">
                                <?= htmlspecialchars($label, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Generate PDF</button>
            </form>
        </div>
    <?php endif; ?>

    <h2>Your bin</h2>
    <?php if ($receipts === []): ?>
        <p class="empty-state">No receipts yet. Import a confirmation by email, generate from a trip, or upload a file.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Brand</th>
                    <th>Trip / stay</th>
                    <th>Source</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receipts as $receipt): ?>
                    <tr>
                        <td>
                            <div><?= htmlspecialchars($formatDate($receipt->travelDate), ENT_QUOTES) ?></div>
                            <?php if ($receipt->travelEndDate !== null && $receipt->travelEndDate !== $receipt->travelDate): ?>
                                <div class="hint">to <?= htmlspecialchars($formatDate($receipt->travelEndDate), ENT_QUOTES) ?></div>
                            <?php endif; ?>
                            <div class="hint"><?= htmlspecialchars(ucfirst($receipt->kind), ENT_QUOTES) ?></div>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($receipt->locationLabel, ENT_QUOTES) ?></div>
                            <?php if ($receipt->confirmationCode !== null): ?>
                                <div class="hint">Conf <?= htmlspecialchars($receipt->confirmationCode, ENT_QUOTES) ?></div>
                            <?php endif; ?>
                            <?php if ($receipt->amount !== null): ?>
                                <div class="hint"><?= htmlspecialchars(
                                    number_format($receipt->amount, 2) . ' ' . ($receipt->currency ?? 'USD'),
                                    ENT_QUOTES
                                ) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($receipt->brand ?? '—', ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($receipt->tripId !== null): ?>
                                <a href="/trips/view.php?id=<?= (int) $receipt->tripId ?>">Trip #<?= (int) $receipt->tripId ?></a>
                            <?php elseif ($receipt->hotelStayId !== null): ?>
                                <a href="/hotels/view.php?id=<?= (int) $receipt->hotelStayId ?>">Stay #<?= (int) $receipt->hotelStayId ?></a>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                            <div class="hint"><?= htmlspecialchars($receipt->title, ENT_QUOTES) ?></div>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($sourceLabel($receipt->source), ENT_QUOTES) ?></div>
                            <div class="hint">Expires <?= htmlspecialchars($formatDate(substr($receipt->expiresAt, 0, 10)), ENT_QUOTES) ?></div>
                        </td>
                        <td>
                            <div class="row-actions" style="margin-top: 0;">
                                <a href="/receipts/download.php?id=<?= (int) $receipt->id ?>">Download</a>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this receipt?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="receipt_id" value="<?= (int) $receipt->id ?>">
                                    <button type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
