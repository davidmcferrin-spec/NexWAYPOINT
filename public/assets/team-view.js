/**
 * Dashboard team view toggle (table / cards / map). Preference in localStorage.
 */
(function () {
    var KEY = 'nexwaypoint-team-view';
    var allowed = { table: true, cards: true, map: true };

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var buttons = document.querySelectorAll('[data-team-view]');
        var panels = document.querySelectorAll('[data-team-panel]');
        if (!buttons.length || !panels.length) {
            return;
        }

        function setView(view) {
            if (!allowed[view]) {
                view = 'table';
            }
            buttons.forEach(function (btn) {
                var active = btn.getAttribute('data-team-view') === view;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                var show = panel.getAttribute('data-team-panel') === view;
                panel.hidden = !show;
            });
            try {
                localStorage.setItem(KEY, view);
            } catch (e) { /* ignore */ }

            if (view === 'map' && window.NEXWAYPOINT_TEAM_MAP_API && typeof window.NEXWAYPOINT_TEAM_MAP_API.invalidate === 'function') {
                window.NEXWAYPOINT_TEAM_MAP_API.invalidate();
            }
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setView(btn.getAttribute('data-team-view') || 'table');
            });
        });

        var saved = 'table';
        try {
            saved = localStorage.getItem(KEY) || 'table';
        } catch (e) { /* ignore */ }
        setView(saved);
    });
})();
