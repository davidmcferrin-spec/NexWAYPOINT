<?php

declare(strict_types=1);

/**
 * Early theme apply (before CSS) + stylesheet + theme.js.
 * Include inside <head> on every HTML page.
 *
 * Asset URLs get ?v=filemtime so deploys invalidate browser caches
 * without requiring a hard refresh.
 */

use NexWaypoint\Core\SiteSettingsRepository;

if (!function_exists('nexwaypoint_asset')) {
    /**
     * @param non-empty-string $webPath Path under public/, e.g. /assets/style.css
     */
    function nexwaypoint_asset(string $webPath): string
    {
        $webPath = '/' . ltrim($webPath, '/');
        $full = __DIR__ . $webPath;
        $version = is_file($full) ? (string) filemtime($full) : '1';
        return $webPath . '?v=' . rawurlencode($version);
    }
}

$nxThemeDefault = 'light';
$nxThemeLock = false;
if (isset($app) && is_array($app) && isset($app['db'], $app['logger'])) {
    try {
        $nxSettings = new SiteSettingsRepository($app['db'], $app['logger']);
        if ($nxSettings->tableReady()) {
            $rawTheme = $nxSettings->get(SiteSettingsRepository::KEY_UI_THEME_DEFAULT, 'light');
            $nxThemeDefault = $rawTheme === 'dark' ? 'dark' : 'light';
            $nxThemeLock = $nxSettings->getBool(SiteSettingsRepository::KEY_UI_THEME_LOCK, false);
        }
    } catch (Throwable) {
        // Keep defaults if settings unavailable (login before migrate, etc.).
    }
}
?>
<script>
window.NEXWAYPOINT_APPEARANCE = <?= json_encode([
    'themeDefault' => $nxThemeDefault,
    'themeLock' => $nxThemeLock,
], JSON_UNESCAPED_UNICODE) ?>;
(function () {
    try {
        var cfg = window.NEXWAYPOINT_APPEARANCE || {};
        var siteDefault = cfg.themeDefault === 'dark' ? 'dark' : 'light';
        var locked = !!cfg.themeLock;
        var t = siteDefault;
        if (!locked) {
            var stored = localStorage.getItem('nexwaypoint-theme');
            if (stored === 'dark' || stored === 'light') {
                t = stored;
            }
        }
        document.documentElement.setAttribute('data-theme', t);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(nexwaypoint_asset('/assets/style.css'), ENT_QUOTES) ?>">
<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/theme.js'), ENT_QUOTES) ?>" defer></script>
