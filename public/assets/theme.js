/**
 * Theme bootstrap: apply before paint to avoid a flash of the wrong theme.
 * Default is light. Persists choice in localStorage.
 */
(function () {
    var KEY = 'nexwaypoint-theme';

    function normalize(value) {
        return value === 'dark' ? 'dark' : 'light';
    }

    function current() {
        try {
            return normalize(localStorage.getItem(KEY));
        } catch (e) {
            return 'light';
        }
    }

    function apply(theme) {
        theme = normalize(theme);
        document.documentElement.setAttribute('data-theme', theme);
        try {
            localStorage.setItem(KEY, theme);
        } catch (e) {
            /* private mode / quota — theme still applies for this page */
        }
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            var next = theme === 'dark' ? 'light' : 'dark';
            btn.setAttribute('aria-label', 'Switch to ' + next + ' mode');
            btn.textContent = next === 'dark' ? 'Dark mode' : 'Light mode';
        });
    }

    apply(current());

    document.addEventListener('DOMContentLoaded', function () {
        apply(current());
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                apply(current() === 'dark' ? 'light' : 'dark');
            });
        });
    });
})();
