<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$repo = new UserRepository($app['db'], $app['logger']);

$errors = [];
$message = null;
$settingsSection = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'update_profile') {
                $displayName = trim((string) ($_POST['display_name'] ?? ''));
                if ($displayName === '') {
                    throw new InvalidArgumentException('Display name is required.');
                }
                $user = $repo->updateProfile(
                    $user->id,
                    $displayName,
                    $user->managerId,
                    $user->isActive,
                    $user->isAdmin,
                    $user->id,
                );
                $message = 'Profile updated.';
            } elseif ($action === 'update_home') {
                $homeCity = trim((string) ($_POST['home_city'] ?? ''));
                $homeState = trim((string) ($_POST['home_state'] ?? ''));
                $lat = null;
                $lon = null;
                if ($homeCity !== '') {
                    $geocoder = new Geocoder($app['logger']);
                    $coords = $geocoder->geocodeCity($homeCity, $homeState !== '' ? $homeState : null, 'US');
                    if ($coords === null) {
                        throw new InvalidArgumentException(
                            'Could not locate that home city. Check spelling or try a nearby city name.'
                        );
                    }
                    $lat = $coords['lat'];
                    $lon = $coords['lon'];
                }
                $user = $repo->updateHomeLocation(
                    $user->id,
                    $homeCity !== '' ? $homeCity : null,
                    $homeState !== '' ? $homeState : null,
                    $lat,
                    $lon,
                    $user->id,
                );
                $message = $homeCity !== ''
                    ? 'Home city saved for the team map.'
                    : 'Home city cleared.';
            } elseif ($action === 'update_photo') {
                $focusX = (float) ($_POST['photo_focus_x'] ?? 50);
                $focusY = (float) ($_POST['photo_focus_y'] ?? 50);
                $remove = isset($_POST['remove_photo']);

                if ($remove) {
                    $old = $user->photoPath;
                    $user = $repo->updatePhoto($user->id, null, 50.0, 50.0, $user->id);
                    if ($old !== null && is_file($old)) {
                        @unlink($old);
                    }
                    $message = 'Photo removed.';
                } else {
                    $maxBytes = NexWaypoint\Core\Env::getInt('AVATAR_MAX_BYTES', 5_242_880);
                    $uploadDir = NexWaypoint\Core\Env::get(
                        'AVATAR_UPLOAD_DIR',
                        dirname(__DIR__, 2) . '/storage/uploads/avatars'
                    );
                    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        throw new RuntimeException('Avatar upload directory is not writable.');
                    }

                    $hasFile = !empty($_FILES['photo']['tmp_name'])
                        && is_uploaded_file($_FILES['photo']['tmp_name']);

                    if ($hasFile) {
                        if ((int) $_FILES['photo']['size'] > $maxBytes) {
                            throw new InvalidArgumentException('Photo exceeds the maximum allowed size.');
                        }
                        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                        $ext = strtolower(pathinfo((string) $_FILES['photo']['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExt, true)) {
                            throw new InvalidArgumentException('Photo must be jpg, png, or webp.');
                        }
                        $filename = $user->id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $destination = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
                        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                            throw new RuntimeException('Could not save uploaded photo.');
                        }
                        $old = $user->photoPath;
                        $user = $repo->updatePhoto($user->id, $destination, $focusX, $focusY, $user->id);
                        if ($old !== null && $old !== $destination && is_file($old)) {
                            @unlink($old);
                        }
                        $message = 'Photo uploaded.';
                    } elseif ($user->hasPhoto()) {
                        $user = $repo->updatePhoto($user->id, $user->photoPath, $focusX, $focusY, $user->id);
                        $message = 'Photo crop updated.';
                    } else {
                        throw new InvalidArgumentException('Choose a photo to upload.');
                    }
                }
            } elseif ($action === 'change_password') {
                $plain = (string) ($_POST['password'] ?? '');
                $confirm = (string) ($_POST['password_confirm'] ?? '');
                if ($plain !== $confirm) {
                    throw new InvalidArgumentException('Passwords do not match.');
                }
                $repo->updatePassword($user->id, $plain, $user->id);
                $message = 'Password updated.';
            } else {
                throw new InvalidArgumentException('Unknown action.');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$avatarUrl = $user->hasPhoto()
    ? '/media/avatar.php?id=' . (int) $user->id
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; My profile</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>My profile</h1>
    <p class="hint">Account, photo, home city for the team map, and password. Email addresses used for mail import are under <a href="/settings/emails.php">My emails</a>.</p>

    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="card">
        <h3>Account</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update_profile">
            <label>Username
                <input type="text" value="<?= htmlspecialchars($user->username, ENT_QUOTES) ?>" disabled>
            </label>
            <p class="hint">Username cannot be changed here.</p>
            <label>Display name
                <input type="text" name="display_name" required maxlength="120"
                    value="<?= htmlspecialchars($user->displayName, ENT_QUOTES) ?>">
            </label>
            <label>Primary email
                <input type="email" value="<?= htmlspecialchars($user->email, ENT_QUOTES) ?>" disabled>
            </label>
            <p class="hint"><a href="/settings/emails.php">Manage primary and alias emails</a></p>
            <button type="submit" class="primary">Save profile</button>
        </form>
    </div>

    <div class="card">
        <h3>Photo</h3>
        <p class="hint">Used on the dashboard table, baseball cards, and team map. Drag inside the circle to center your face.</p>
        <form method="post" enctype="multipart/form-data" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update_photo">
            <input type="hidden" name="photo_focus_x" id="photo_focus_x"
                value="<?= htmlspecialchars((string) $user->photoFocusX, ENT_QUOTES) ?>">
            <input type="hidden" name="photo_focus_y" id="photo_focus_y"
                value="<?= htmlspecialchars((string) $user->photoFocusY, ENT_QUOTES) ?>">

            <div id="avatar-crop-preview" class="avatar-crop-preview<?= $avatarUrl ? ' has-image' : '' ?>"
                title="Drag to center your face">
                <?php if ($avatarUrl): ?>
                    <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES) ?>"
                        alt="Your photo"
                        style="object-position: <?= (float) $user->photoFocusX ?>% <?= (float) $user->photoFocusY ?>%;">
                <?php else: ?>
                    <img src="" alt="" hidden>
                    <span class="avatar-crop-placeholder">No photo</span>
                <?php endif; ?>
            </div>

            <label>Upload photo
                <input type="file" name="photo" id="avatar-file-input" accept="image/png,image/jpeg,image/webp">
            </label>
            <div class="form-row">
                <button type="submit" class="primary">Save photo</button>
                <?php if ($user->hasPhoto()): ?>
                    <button type="submit" class="secondary" name="remove_photo" value="1">Remove photo</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Home city</h3>
        <p class="hint">Where you appear on the team map when status is Home, Office, or Unavailable.</p>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update_home">
            <div class="form-row">
                <label>City
                    <input type="text" name="home_city" maxlength="120"
                        value="<?= htmlspecialchars((string) ($user->homeCity ?? ''), ENT_QUOTES) ?>"
                        placeholder="Huntsville">
                </label>
                <label>State
                    <input type="text" name="home_state" maxlength="120"
                        value="<?= htmlspecialchars((string) ($user->homeState ?? ''), ENT_QUOTES) ?>"
                        placeholder="AL">
                </label>
            </div>
            <button type="submit" class="primary">Save home city</button>
        </form>
    </div>

    <div class="card">
        <h3>Change password</h3>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="change_password">
            <label>New password (min 12)
                <input type="password" name="password" required minlength="12" autocomplete="new-password">
            </label>
            <label>Confirm password
                <input type="password" name="password_confirm" required minlength="12" autocomplete="new-password">
            </label>
            <button type="submit" class="primary">Update password</button>
        </form>
    </div>
</main>
<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/avatar-crop.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
