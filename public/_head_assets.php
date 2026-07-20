<?php

declare(strict_types=1);

/**
 * Early theme apply (before CSS) + stylesheet + theme.js.
 * Include inside <head> on every HTML page.
 */
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
<link rel="stylesheet" href="/assets/style.css">
<script src="/assets/theme.js" defer></script>
