<?php

declare(strict_types=1);

use NexWaypoint\Core\AppearanceCatalog;
use NexWaypoint\Core\Csrf;
use NexWaypoint\Core\SiteSettingsRepository;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$userRepo = new UserRepository($app['db'], $app['logger']);

if (!$userRepo->isAdmin($user)) {
    http_response_code(403);
    echo 'Site admin access required.';
    exit;
}

$settings = new SiteSettingsRepository($app['db'], $app['logger']);
$settingsSection = 'appearance';
$errors = [];
$message = null;
$basemaps = AppearanceCatalog::mapBasemaps();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } elseif (!$settings->tableReady()) {
        $errors[] = 'site_settings table missing. Run: php scripts/migrate.php';
    } else {
        $mapId = trim((string) ($_POST['map_basemap'] ?? ''));
        if (!isset($basemaps[$mapId])) {
            $errors[] = 'Choose a valid map basemap.';
        }

        $themeDefault = trim((string) ($_POST['ui_theme_default'] ?? 'light'));
        if (!in_array($themeDefault, ['light', 'dark'], true)) {
            $errors[] = 'Default theme must be light or dark.';
        }

        $hotelColor = AppearanceCatalog::normalizeHexColor(
            $_POST['map_hotel_color'] ?? null,
            AppearanceCatalog::defaultHotelColor()
        );
        $venueColor = AppearanceCatalog::normalizeHexColor(
            $_POST['map_venue_color'] ?? null,
            AppearanceCatalog::defaultVenueColor()
        );
        $blacklistColor = AppearanceCatalog::normalizeHexColor(
            $_POST['map_blacklist_color'] ?? null,
            AppearanceCatalog::defaultBlacklistColor()
        );
        $feeColor = AppearanceCatalog::normalizeHexColor(
            $_POST['map_fee_color'] ?? null,
            AppearanceCatalog::defaultFeeColor()
        );

        // Reject if user typed invalid hex (normalizeHex falls back silently — detect that).
        foreach ([
            'map_hotel_color' => $hotelColor,
            'map_venue_color' => $venueColor,
            'map_blacklist_color' => $blacklistColor,
            'map_fee_color' => $feeColor,
        ] as $field => $normalized) {
            $raw = trim((string) ($_POST[$field] ?? ''));
            if ($raw !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $raw) !== 1) {
                $errors[] = "{$field} must be a #RRGGBB color.";
            }
        }

        if ($errors === []) {
            $settings->setMany([
                SiteSettingsRepository::KEY_MAP_STYLE => $mapId,
                SiteSettingsRepository::KEY_UI_THEME_DEFAULT => $themeDefault,
                SiteSettingsRepository::KEY_UI_THEME_LOCK => isset($_POST['ui_theme_lock']) ? '1' : '0',
                SiteSettingsRepository::KEY_MAP_HOTEL_COLOR => $hotelColor,
                SiteSettingsRepository::KEY_MAP_VENUE_COLOR => $venueColor,
                SiteSettingsRepository::KEY_MAP_BLACKLIST_COLOR => $blacklistColor,
                SiteSettingsRepository::KEY_MAP_FEE_COLOR => $feeColor,
            ], $user->id);
            $message = 'Appearance settings saved. They apply site-wide for all users.';
        }
    }
}

$currentMap = AppearanceCatalog::resolveMapBasemap(
    $settings->get(SiteSettingsRepository::KEY_MAP_STYLE, AppearanceCatalog::defaultMapBasemap())
);
$themeDefault = $settings->get(SiteSettingsRepository::KEY_UI_THEME_DEFAULT, 'light') === 'dark' ? 'dark' : 'light';
$themeLock = $settings->getBool(SiteSettingsRepository::KEY_UI_THEME_LOCK, false);
$hotelColor = AppearanceCatalog::normalizeHexColor(
    $settings->get(SiteSettingsRepository::KEY_MAP_HOTEL_COLOR),
    AppearanceCatalog::defaultHotelColor()
);
$venueColor = AppearanceCatalog::normalizeHexColor(
    $settings->get(SiteSettingsRepository::KEY_MAP_VENUE_COLOR),
    AppearanceCatalog::defaultVenueColor()
);
$blacklistColor = AppearanceCatalog::normalizeHexColor(
    $settings->get(SiteSettingsRepository::KEY_MAP_BLACKLIST_COLOR),
    AppearanceCatalog::defaultBlacklistColor()
);
$feeColor = AppearanceCatalog::normalizeHexColor(
    $settings->get(SiteSettingsRepository::KEY_MAP_FEE_COLOR),
    AppearanceCatalog::defaultFeeColor()
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors !== []) {
    $mapIdPost = trim((string) ($_POST['map_basemap'] ?? ''));
    if (isset($basemaps[$mapIdPost])) {
        $currentMap = AppearanceCatalog::resolveMapBasemap($mapIdPost);
    }
    $themeDefault = in_array($_POST['ui_theme_default'] ?? '', ['light', 'dark'], true)
        ? (string) $_POST['ui_theme_default']
        : $themeDefault;
    $themeLock = isset($_POST['ui_theme_lock']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Appearance</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>Appearance</h1>
    <p class="hint">
        Site-wide look-and-feel. Map basemap and pin colors affect every user.
        Theme default applies when a browser has no personal choice (or when locked).
    </p>

    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <?php if (!$settings->tableReady()): ?>
        <p class="alert alert-error">Run <code>php scripts/migrate.php</code> to create <code>site_settings</code>.</p>
    <?php else: ?>
        <form method="post" class="stack">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

            <fieldset>
                <legend>Map basemap</legend>
                <p class="hint">
                    OpenStreetMap data with different free tile styles. Current:
                    <strong><?= htmlspecialchars($currentMap['label'], ENT_QUOTES) ?></strong>.
                    Preview on <a href="/hotels/map.php">Hotel map</a>.
                </p>
                <?php foreach ($basemaps as $id => $meta): ?>
                    <label class="radio-block">
                        <input type="radio" name="map_basemap" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
                            <?= $currentMap['id'] === $id ? 'checked' : '' ?>>
                        <span>
                            <strong><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></strong>
                            <span class="hint"><?= htmlspecialchars($meta['description'], ENT_QUOTES) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset>
                <legend>Map pin colors</legend>
                <div class="form-row-2">
                    <label>Hotel
                        <input type="color" name="map_hotel_color" value="<?= htmlspecialchars($hotelColor, ENT_QUOTES) ?>">
                    </label>
                    <label>Office / venue
                        <input type="color" name="map_venue_color" value="<?= htmlspecialchars($venueColor, ENT_QUOTES) ?>">
                    </label>
                    <label>Blacklisted hotel
                        <input type="color" name="map_blacklist_color" value="<?= htmlspecialchars($blacklistColor, ENT_QUOTES) ?>">
                    </label>
                    <label>Destination fee hotel
                        <input type="color" name="map_fee_color" value="<?= htmlspecialchars($feeColor, ENT_QUOTES) ?>">
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend>UI theme</legend>
                <label>Default theme (for browsers with no saved preference)
                    <select name="ui_theme_default">
                        <option value="light" <?= $themeDefault === 'light' ? 'selected' : '' ?>>Light</option>
                        <option value="dark" <?= $themeDefault === 'dark' ? 'selected' : '' ?>>Dark</option>
                    </select>
                </label>
                <label>
                    <input type="checkbox" name="ui_theme_lock" value="1" <?= $themeLock ? 'checked' : '' ?>>
                    Lock theme for everyone (hides personal Dark/Light toggle)
                </label>
            </fieldset>

            <button type="submit" class="primary">Save appearance</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
