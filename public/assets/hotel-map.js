/**
 * Leaflet map for hotels + office/venues (data from window.NEXWAYPOINT_HOTEL_MAP).
 */
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var el = document.getElementById('hotel-map');
        var payload = window.NEXWAYPOINT_HOTEL_MAP;
        if (!el || !payload || typeof L === 'undefined') {
            return;
        }

        var hotels = payload.hotels || [];
        var venues = payload.venues || [];
        if (!hotels.length && !venues.length) {
            return;
        }

        var map = L.map(el, { scrollWheelZoom: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        var bounds = [];

        hotels.forEach(function (h) {
            var color = h.blacklisted ? '#b91c1c' : (h.destination_fee ? '#a16207' : '#0369a1');
            var marker = L.circleMarker([h.lat, h.lon], {
                radius: 9,
                color: color,
                fillColor: color,
                fillOpacity: 0.85,
                weight: 2
            }).addTo(map);

            var bits = ['<strong>' + escapeHtml(h.name) + '</strong>', 'Hotel'];
            if (h.brand) {
                bits.push(escapeHtml(h.brand));
            }
            if (h.place) {
                bits.push(escapeHtml(h.place));
            }
            if (h.rating != null) {
                bits.push('Rating: ' + Number(h.rating).toFixed(1) + ' / 5');
            }
            if (h.blacklisted) {
                bits.push('<span style="color:#b91c1c">Blacklisted</span>');
            }
            if (h.destination_fee) {
                bits.push('Destination charge');
            }
            if (h.approx) {
                bits.push('<em>City-level pin</em>');
            }
            bits.push('<a href="' + escapeHtml(h.url) + '">Open</a>');
            marker.bindPopup(bits.join('<br>'));
            bounds.push([h.lat, h.lon]);
        });

        venues.forEach(function (v) {
            var color = '#047857';
            var marker = L.marker([v.lat, v.lon], {
                icon: L.divIcon({
                    className: 'venue-map-marker',
                    html: '<span style="display:block;width:14px;height:14px;background:' + color + ';border:2px solid #064e3b;box-shadow:0 0 0 1px #fff"></span>',
                    iconSize: [14, 14],
                    iconAnchor: [7, 7]
                })
            }).addTo(map);

            var bits = ['<strong>' + escapeHtml(v.name) + '</strong>', 'Office / venue'];
            if (v.place) {
                bits.push(escapeHtml(v.place));
            }
            if (v.notes) {
                bits.push(escapeHtml(v.notes));
            }
            if (v.approx) {
                bits.push('<em>City-level pin</em>');
            }
            if (v.url) {
                bits.push('<a href="' + escapeHtml(v.url) + '">Edit</a>');
            }
            marker.bindPopup(bits.join('<br>'));
            bounds.push([v.lat, v.lon]);
        });

        if (bounds.length === 1) {
            map.setView(bounds[0], 11);
        } else {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 });
        }
    });

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
