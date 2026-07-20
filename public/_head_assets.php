<?php

declare(strict_types=1);

/**
 * Early theme apply (before CSS) + stylesheet + theme.js.
 * Include inside <head> on every HTML page.
 *
 * Asset URLs get ?v=filemtime so deploys invalidate browser caches
 * without requiring a hard refresh.
 */
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
?>
<script>
(function () {
    try {
        var t = localStorage.getItem('nexwaypoint-theme');
        document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(nexwaypoint_asset('/assets/style.css'), ENT_QUOTES) ?>">
<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/theme.js'), ENT_QUOTES) ?>" defer></script>
