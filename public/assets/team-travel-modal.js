/**
 * Teammate travel preview modal (table / cards / map).
 * Data: window.NEXWAYPOINT_TEAM_PROFILES
 * Trip itinerary items: {type: leg|layover|hotel, label: string}
 */
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function itineraryItems(trip) {
        if (trip.itinerary && trip.itinerary.length) {
            return trip.itinerary;
        }
        // Older payload shape: flat flights list.
        if (trip.flights && trip.flights.length) {
            return trip.flights.map(function (f) {
                return { type: 'leg', label: f.label };
            });
        }
        return [];
    }

    ready(function () {
        var profiles = window.NEXWAYPOINT_TEAM_PROFILES || {};
        var modal = document.getElementById('teammate-travel-modal');
        if (!modal) {
            return;
        }

        var titleEl = document.getElementById('teammate-travel-modal-title');
        var metaEl = document.getElementById('teammate-travel-modal-meta');
        var bodyEl = document.getElementById('teammate-travel-modal-body');

        function openFor(userId) {
            var profile = profiles[String(userId)];
            if (!profile) {
                return;
            }
            if (titleEl) {
                titleEl.textContent = profile.name || 'Teammate';
            }
            if (metaEl) {
                var bits = [];
                if (profile.status_label) {
                    bits.push(escapeHtml(profile.status_label));
                }
                if (profile.location) {
                    bits.push(escapeHtml(profile.location));
                }
                metaEl.innerHTML = bits.join(' · ');
            }
            if (bodyEl) {
                var trips = profile.trips || [];
                if (!trips.length) {
                    bodyEl.innerHTML = '<p class="empty-state">No visible travel in the next '
                        + escapeHtml(String(profile.window_days || 21))
                        + ' days.</p>';
                } else {
                    var html = '<ul class="teammate-trip-list">';
                    trips.forEach(function (t) {
                        html += '<li class="teammate-trip-item">';
                        var heading = t.destination
                            ? escapeHtml(t.destination)
                            : (t.redacted ? 'Travel' : 'Trip');
                        html += '<strong>' + heading + '</strong>';
                        if (t.dates) {
                            html += '<div>' + escapeHtml(t.dates) + '</div>';
                        }
                        if (t.purpose) {
                            html += '<div class="hint">' + escapeHtml(t.purpose) + '</div>';
                        }
                        if (t.notes) {
                            html += '<div class="hint">' + escapeHtml(t.notes) + '</div>';
                        }
                        var items = itineraryItems(t);
                        if (items.length) {
                            html += '<ul class="teammate-itinerary">';
                            items.forEach(function (item) {
                                var cls = 'teammate-itinerary-item teammate-itinerary-'
                                    + escapeHtml(item.type || 'leg');
                                html += '<li class="' + cls + '">' + escapeHtml(item.label) + '</li>';
                            });
                            html += '</ul>';
                        }
                        html += '</li>';
                    });
                    html += '</ul>';
                    bodyEl.innerHTML = html;
                }
            }
            modal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('modal-open');
        }

        document.querySelectorAll('[data-open-teammate]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                if (ev.target.closest && ev.target.closest('a')) {
                    return;
                }
                var id = el.getAttribute('data-open-teammate');
                if (id) {
                    openFor(id);
                }
            });
            el.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    var id = el.getAttribute('data-open-teammate');
                    if (id) {
                        openFor(id);
                    }
                }
            });
        });

        modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
        modal.addEventListener('click', function (ev) {
            if (ev.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        window.NEXWAYPOINT_OPEN_TEAMMATE = openFor;
    });
})();
