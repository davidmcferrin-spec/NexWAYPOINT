/**
 * Teammate travel preview modal (table / cards / map).
 * Data: window.NEXWAYPOINT_TEAM_PROFILES
 * Trip stays: {city, dates, open_ended} — not the flight-by-flight itinerary.
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

    function stayItems(trip) {
        if (trip.stays && trip.stays.length) {
            return trip.stays;
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
                    bits.push('Currently in ' + escapeHtml(profile.location));
                }
                if (profile.next && profile.next.city_label) {
                    var nextBit = 'Next: ' + escapeHtml(profile.next.city_label);
                    if (profile.next.dates) {
                        nextBit += ' · ' + escapeHtml(profile.next.dates);
                    }
                    if (profile.next.time_of_day) {
                        nextBit += ' · ' + escapeHtml(profile.next.time_of_day);
                    }
                    bits.push(nextBit);
                }
                metaEl.innerHTML = bits.join('<br>');
            }
            if (bodyEl) {
                var trips = profile.trips || [];
                var windowDays = escapeHtml(String(profile.window_days || 21));
                if (!trips.length) {
                    bodyEl.innerHTML = '<p class="empty-state">No visible travel in the next '
                        + windowDays
                        + ' days.</p>';
                } else {
                    var html = '<h3 class="teammate-travel-heading">Next '
                        + windowDays
                        + ' days</h3>';
                    html += '<ul class="teammate-trip-list">';
                    trips.forEach(function (t) {
                        html += '<li class="teammate-trip-item">';
                        var stays = stayItems(t);
                        if (stays.length) {
                            html += '<ul class="teammate-stay-list">';
                            stays.forEach(function (s) {
                                var city = s.city || (t.redacted ? 'Travel' : 'Stay');
                                html += '<li class="teammate-stay-item">';
                                html += '<strong>' + escapeHtml(city) + '</strong>';
                                if (s.dates) {
                                    html += '<div>' + escapeHtml(s.dates) + '</div>';
                                }
                                if (s.purpose) {
                                    html += '<div class="hint">' + escapeHtml(s.purpose) + '</div>';
                                }
                                html += '</li>';
                            });
                            html += '</ul>';
                        } else {
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
