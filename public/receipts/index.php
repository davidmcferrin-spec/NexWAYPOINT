<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Receipts\ExpenseReceipt;
use NexWaypoint\Receipts\ExpenseReceiptRepository;
use NexWaypoint\Receipts\ReceiptCaptureService;
use NexWaypoint\Receipts\ReceiptFileStore;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$db = $app['db'];
$logger = $app['logger'];

$schemaWarning = null;
if (!$db->tableExists('expense_receipts')) {
    $schemaWarning = 'Database is missing expense_receipts. On the server run: php scripts/migrate.php';
}

$receiptRepo = $schemaWarning === null ? new ExpenseReceiptRepository($db, $logger) : null;
$fileStore = new ReceiptFileStore(NEXWAYPOINT_ROOT . '/storage/receipts', $logger);

$errors = [];
$message = null;

if ($receiptRepo !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'delete') {
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

$retentionDays = ReceiptCaptureService::retentionDaysFromEnv();
$sourceLabel = static function (string $source): string {
    return match ($source) {
        ExpenseReceipt::SOURCE_ATTACHMENT => 'Vendor PDF',
        ExpenseReceipt::SOURCE_EMAIL_BODY => 'Vendor email',
        ExpenseReceipt::SOURCE_GENERATED => 'Generated (legacy)',
        ExpenseReceipt::SOURCE_UPLOAD => 'Upload (legacy)',
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
                Vendor confirmation PDFs from mail import — kept about <?= (int) $retentionDays ?> days.
                Attached vendor PDFs are archived as-is; otherwise the confirmation email is saved as a PDF.
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

    <h2>Your bin</h2>
    <?php if ($receipts === []): ?>
        <p class="empty-state">No receipts yet. Forward or receive a vendor confirmation with a PDF attachment, or a confirmation email with transaction details.</p>
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
