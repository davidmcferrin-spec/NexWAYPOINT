<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

$propertyRepo = new HotelPropertyRepository($app['db'], $app['logger']);
$stayRepo = new HotelStayRepository($app['db'], $app['logger'], $propertyRepo);
$blockRepo = new VisibilityBlockRepository($app['db']);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stay = $stayRepo->find($id);

if ($stay === null || $stay->userId !== $user->id) {
    http_response_code(404);
    echo 'Stay not found.';
    exit;
}

$property = $propertyRepo->find($stay->hotelPropertyId);
if ($property === null) {
    http_response_code(404);
    echo 'Property not found.';
    exit;
}

$errors = [];
$message = null;

$nullable = static function (?string $value): ?string {
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
};

$checkbox = static fn (string $key): bool => isset($_POST[$key]) && $_POST[$key] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } elseif (($_POST['action'] ?? '') === 'merge') {
        try {
            $absorbId = (int) ($_POST['absorb_stay_id'] ?? 0);
            if ($absorbId <= 0) {
                throw new InvalidArgumentException('Choose a stay to merge into this one.');
            }
            $stay = $stayRepo->mergeStays((int) $stay->id, $absorbId, $user->id);
            $property = $propertyRepo->find($stay->hotelPropertyId) ?? $property;
            $message = 'Stays merged. Confirmation, dates, and trip links were combined into this stay.';
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $app['logger']->error('Stay merge failed', ['error' => $e->getMessage()]);
            $errors[] = 'Could not merge stays. Please try again.';
        }
    } else {
        try {
            $ratingRaw = trim((string) ($_POST['stay_rating'] ?? ''));
            $stayRating = $ratingRaw === '' ? null : (int) $ratingRaw;

            $updated = $stayRepo->update(new HotelStay(
                id: $stay->id,
                userId: $stay->userId,
                hotelPropertyId: $stay->hotelPropertyId,
                roomNumber: $nullable($_POST['room_number'] ?? null),
                bedType: $nullable($_POST['bed_type'] ?? null),
                bathroomType: $nullable($_POST['bathroom_type'] ?? null),
                stayStart: (string) ($_POST['stay_start'] ?? ''),
                stayEnd: (string) ($_POST['stay_end'] ?? ''),
                stayRating: $stayRating,
                lastStayPrice: ($_POST['last_stay_price'] ?? '') !== '' ? (float) $_POST['last_stay_price'] : null,
                currency: trim((string) ($_POST['currency'] ?? 'USD')) ?: 'USD',
                bookingSource: $nullable($_POST['booking_source'] ?? null),
                confirmationCode: $nullable($_POST['confirmation_code'] ?? null),
                wouldReturn: isset($_POST['would_return']) && $_POST['would_return'] !== ''
                    ? $_POST['would_return'] === '1'
                    : null,
                notes: $nullable($_POST['notes'] ?? null),
                isPrivate: $checkbox('is_private'),
            ), $user->id);

            $stay = $updated;
            $property = $propertyRepo->find($stay->hotelPropertyId) ?? $property;

            if (!$stay->isPrivate) {
                $hideFrom = array_map('intval', $_POST['hide_from'] ?? []);
                $blockRepo->replaceBlocks(
                    $user->id,
                    VisibilityBlockRepository::TYPE_HOTEL_STAY,
                    (int) $stay->id,
                    $hideFrom,
                    $user->id
                );
            } else {
                $blockRepo->replaceBlocks(
                    $user->id,
                    VisibilityBlockRepository::TYPE_HOTEL_STAY,
                    (int) $stay->id,
                    [],
                    $user->id
                );
            }

            $message = 'Stay saved. Overall property rating updated from all users\' stay ratings.';
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $app['logger']->error('Stay edit failed', ['error' => $e->getMessage()]);
            $errors[] = 'Could not save the stay. Please try again.';
        }
    }
}

$val = static function (string $key, mixed $fallback = '') use ($stay): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'merge' && array_key_exists($key, $_POST)) {
        return (string) $_POST[$key];
    }
    return match ($key) {
        'stay_start' => $stay->stayStart,
        'stay_end' => $stay->stayEnd,
        'room_number' => (string) ($stay->roomNumber ?? ''),
        'bed_type' => (string) ($stay->bedType ?? ''),
        'bathroom_type' => (string) ($stay->bathroomType ?? ''),
        'stay_rating' => $stay->stayRating !== null ? (string) $stay->stayRating : '',
        'would_return' => $stay->wouldReturn === null ? '' : ($stay->wouldReturn ? '1' : '0'),
        'notes' => (string) ($stay->notes ?? ''),
        'last_stay_price' => $stay->lastStayPrice !== null ? (string) $stay->lastStayPrice : '',
        'currency' => $stay->currency,
        'booking_source' => (string) ($stay->bookingSource ?? ''),
        'confirmation_code' => (string) ($stay->confirmationCode ?? ''),
        default => (string) $fallback,
    };
};

$userRepo = new UserRepository($app['db'], $app['logger']);
$otherUsers = array_values(array_filter(
    $userRepo->findAllActive(),
    static fn ($u) => $u->id !== $user->id
));
$isPrivate = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'merge'
    ? $checkbox('is_private')
    : $stay->isPrivate;
$blockedUserIds = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'merge'
    ? array_map('intval', $_POST['hide_from'] ?? [])
    : $blockRepo->blockedUserIds(VisibilityBlockRepository::TYPE_HOTEL_STAY, (int) $stay->id);

// Merge candidates: other stays for this user; same property first.
$mergeCandidates = [];
foreach ($stayRepo->findForUser($user->id, 'stay_start DESC') as $candidate) {
    if ($candidate->id === null || (int) $candidate->id === (int) $stay->id) {
        continue;
    }
    $candProp = $propertyRepo->find($candidate->hotelPropertyId);
    $sameProperty = (int) $candidate->hotelPropertyId === (int) $stay->hotelPropertyId;
    $mergeCandidates[] = [
        'stay_id' => (int) $candidate->id,
        'same_property' => $sameProperty,
        'label' => ($candProp !== null ? $candProp->hotelName : 'Hotel')
            . ' · ' . $candidate->stayStart . ' → ' . $candidate->stayEnd
            . ($candidate->confirmationCode ? ' · #' . $candidate->confirmationCode : '')
            . ($sameProperty ? '' : ' (different property)'),
    ];
}
usort($mergeCandidates, static function (array $a, array $b): int {
    if ($a['same_property'] !== $b['same_property']) {
        return $a['same_property'] ? -1 : 1;
    }
    return $b['stay_id'] <=> $a['stay_id'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Edit stay</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <p><a href="/hotels/view.php?id=<?= (int) $stay->id ?>">&larr; Back to stay</a></p>
    <h1>Edit stay</h1>
    <p class="hint">
        <?= htmlspecialchars($property->hotelName, ENT_QUOTES) ?>
        <?php if ($property->city): ?>
            · <?= htmlspecialchars($property->city, ENT_QUOTES) ?>
        <?php endif; ?>
        <?php if ($property->overallRating !== null): ?>
            · Public average <?= number_format($property->overallRating, 1) ?>★
        <?php endif; ?>
    </p>

    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form method="post" class="stack">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

        <fieldset>
            <legend>This stay</legend>
            <label>Check-in date
                <input type="date" name="stay_start" required value="<?= htmlspecialchars($val('stay_start'), ENT_QUOTES) ?>">
            </label>
            <label>Check-out date
                <input type="date" name="stay_end" required value="<?= htmlspecialchars($val('stay_end'), ENT_QUOTES) ?>">
            </label>
            <label>Room number
                <input type="text" name="room_number" value="<?= htmlspecialchars($val('room_number'), ENT_QUOTES) ?>">
            </label>
            <label>Bed type
                <select name="bed_type">
                    <option value="">—</option>
                    <?php foreach (['king' => 'King', 'queen' => 'Queen', 'dual_queen' => 'Dual queen'] as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $val('bed_type') === $k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Bathroom
                <select name="bathroom_type">
                    <option value="">—</option>
                    <?php foreach (['tub' => 'Tub', 'walk_in_shower' => 'Walk-in shower'] as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $val('bathroom_type') === $k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Stay rating (0–5 stars) — averages into the public overall rating
                <select name="stay_rating">
                    <option value="">Not rated yet</option>
                    <?php for ($i = 0; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>" <?= $val('stay_rating') === (string) $i ? 'selected' : '' ?>>
                            <?= $i === 0 ? '0 ★ (worst)' : str_repeat('★', $i) . " ({$i})" ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Would you return?
                <select name="would_return">
                    <option value="">—</option>
                    <option value="1" <?= $val('would_return') === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $val('would_return') === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </label>
            <label>Notes
                <textarea name="notes" rows="3"><?= htmlspecialchars($val('notes'), ENT_QUOTES) ?></textarea>
            </label>
            <label>Last stay price
                <input type="number" step="0.01" name="last_stay_price" value="<?= htmlspecialchars($val('last_stay_price'), ENT_QUOTES) ?>">
            </label>
            <label>Currency
                <input type="text" name="currency" value="<?= htmlspecialchars($val('currency'), ENT_QUOTES) ?>" maxlength="3">
            </label>
            <label>Booking source
                <input type="text" name="booking_source" value="<?= htmlspecialchars($val('booking_source'), ENT_QUOTES) ?>">
            </label>
            <label>Confirmation code
                <input type="text" name="confirmation_code" value="<?= htmlspecialchars($val('confirmation_code'), ENT_QUOTES) ?>">
            </label>
        </fieldset>

        <?php
        $legend = 'Privacy';
        require __DIR__ . '/../_privacy_fieldset.php';
        ?>

        <button type="submit" class="primary">Save stay</button>
    </form>

    <?php if ($mergeCandidates !== []): ?>
        <form method="post" class="stack" style="margin-top: 2rem;"
              onsubmit="return confirm('Merge the selected stay into this one? The other stay will be deleted.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="merge">
            <fieldset>
                <legend>Merge duplicate stay</legend>
                <p class="hint">
                    Use this when you logged a stay manually and email import created a second copy.
                    This stay is kept; ratings/room notes win here; confirmation + booking dates come from the other stay when it has a confirmation code.
                </p>
                <label>Absorb into this stay
                    <select name="absorb_stay_id" required>
                        <option value="">— Select duplicate —</option>
                        <?php foreach ($mergeCandidates as $opt): ?>
                            <option value="<?= (int) $opt['stay_id'] ?>"><?= htmlspecialchars($opt['label'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="secondary">Merge into this stay</button>
            </fieldset>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
