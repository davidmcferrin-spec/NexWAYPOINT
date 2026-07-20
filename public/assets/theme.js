/**
 * Theme bootstrap: apply before paint to avoid a flash of the wrong theme.
 * Site default / lock come from window.NEXWAYPOINT_APPEARANCE (Settings → Appearance).
 * Personal choice persists in localStorage unless the site theme is locked.
 */
(function () {
    var KEY = 'nexwaypoint-theme';

    function siteCfg() {
        return window.NEXWAYPOINT_APPEARANCE || {};
    }

    function normalize(value) {
        return value === 'dark' ? 'dark' : 'light';
    }

    function siteDefault() {
        return normalize(siteCfg().themeDefault);
    }

    function isLocked() {
        return !!siteCfg().themeLock;
    }

    function current() {
        if (isLocked()) {
            return siteDefault();
        }
        try {
            var stored = localStorage.getItem(KEY);
            if (stored === 'dark' || stored === 'light') {
                return stored;
            }
        } catch (e) {
            /* ignore */
        }
        return siteDefault();
    }

    function apply(theme, persist) {
        theme = normalize(theme);
        document.documentElement.setAttribute('data-theme', theme);
        if (persist && !isLocked()) {
            try {
                localStorage.setItem(KEY, theme);
            } catch (e) {
                /* private mode / quota — theme still applies for this page */
            }
        }
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            if (isLocked()) {
                btn.hidden = true;
                return;
            }
            btn.hidden = false;
            var next = theme === 'dark' ? 'light' : 'dark';
            btn.setAttribute('aria-label', 'Switch to ' + next + ' mode');
            btn.textContent = next === 'dark' ? 'Dark mode' : 'Light mode';
        });
        document.querySelectorAll('.theme-toggle-wrap').forEach(function (wrap) {
            wrap.hidden = isLocked();
        });
    }

    apply(current(), false);

    document.addEventListener('DOMContentLoaded', function () {
        apply(current(), false);
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (isLocked()) {
                    return;
                }
                apply(current() === 'dark' ? 'light' : 'dark', true);
            });
        });
    });
})();
