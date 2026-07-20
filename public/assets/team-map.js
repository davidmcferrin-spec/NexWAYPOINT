/**
 * Leaflet team map: city clusters at low zoom, face markers at higher zoom.
 * Data from window.NEXWAYPOINT_TEAM_MAP. Exposes invalidate() for hidden panels.
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
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function cityGroups(people) {
        var groups = {};
        people.forEach(function (p) {
            var key = p.city_key || (p.lat + ',' + p.lon);
            if (!groups[key]) {
                groups[key] = {
                    key: key,
                    label: p.city_label || 'Unknown',
                    lat: p.lat,
                    lon: p.lon,
                    people: []
                };
            }
            groups[key].people.push(p);
        });
        return Object.keys(groups).map(function (k) { return groups[k]; });
    }

    function spiralOffset(index, total) {
        if (total <= 1) {
            return { dLat: 0, dLon: 0 };
        }
        var angle = (index / total) * Math.PI * 2;
        var radius = 0.012 + Math.floor(index / 8) * 0.008;
        return {
            dLat: Math.sin(angle) * radius,
            dLon: Math.cos(angle) * radius
        };
    }

    function faceIcon(person) {
        var focus = (Number(person.photo_focus_x) || 50) + '% ' + (Number(person.photo_focus_y) || 50) + '%';
        var inner;
        if (person.avatar_url) {
            inner = '<img class="team-map-face-img" src="' + escapeHtml(person.avatar_url) + '" alt="" style="object-position:' + escapeHtml(focus) + '">';
        } else {
            inner = '<span class="team-map-face-fallback">' + escapeHtml(person.initials || '?') + '</span>';
        }
        return L.divIcon({
            className: 'team-map-face-marker',
            html: '<div class="team-map-face">' + inner + '</div>',
            iconSize: [44, 44],
            iconAnchor: [22, 22]
        });
    }

    function clusterIcon(count, label) {
        return L.divIcon({
            className: 'team-map-cluster-marker',
            html: '<div class="team-map-cluster" title="' + escapeHtml(label) + '">' + count + '</div>',
            iconSize: [40, 40],
            iconAnchor: [20, 20]
        });
    }

    ready(function () {
        var el = document.getElementById('team-map');
        var payload = window.NEXWAYPOINT_TEAM_MAP;
        if (!el || !payload || typeof L === 'undefined') {
            return;
        }

        var people = payload.people || [];
        if (!people.length) {
            return;
        }

        var basemap = payload.basemap || {};
        var map = L.map(el, { scrollWheelZoom: true });
        L.tileLayer(basemap.url || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: basemap.maxZoom || 19,
            attribution: basemap.attribution || '&copy; OpenStreetMap'
        }).addTo(map);

        var clusterLayer = L.layerGroup().addTo(map);
        var faceLayer = L.layerGroup().addTo(map);
        var groups = cityGroups(people);
        var FACE_ZOOM = 8;

        function render() {
            var zoom = map.getZoom();
            clusterLayer.clearLayers();
            faceLayer.clearLayers();

            if (zoom < FACE_ZOOM) {
                groups.forEach(function (g) {
                    var marker = L.marker([g.lat, g.lon], {
                        icon: clusterIcon(g.people.length, g.label)
                    });
                    marker.bindPopup(
                        '<strong>' + escapeHtml(g.label) + '</strong><br>' +
                        g.people.length + ' teammate' + (g.people.length === 1 ? '' : 's') +
                        '<br><em>Zoom in to see faces</em>'
                    );
                    marker.on('click', function () {
                        map.setView([g.lat, g.lon], Math.max(FACE_ZOOM, zoom + 2));
                    });
                    clusterLayer.addLayer(marker);
                });
            } else {
                groups.forEach(function (g) {
                    g.people.forEach(function (p, i) {
                        var off = spiralOffset(i, g.people.length);
                        var marker = L.marker([g.lat + off.dLat, g.lon + off.dLon], {
                            icon: faceIcon(p)
                        });
                        marker.bindPopup(
                            '<strong>' + escapeHtml(p.name) + '</strong><br>' +
                            escapeHtml(p.label) +
                            (p.city_label ? '<br>' + escapeHtml(p.city_label) : '')
                        );
                        faceLayer.addLayer(marker);
                    });
                });
            }
        }

        var bounds = people.map(function (p) { return [p.lat, p.lon]; });
        if (bounds.length === 1) {
            map.setView(bounds[0], 6);
        } else {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 7 });
        }

        map.on('zoomend', render);
        render();

        window.NEXWAYPOINT_TEAM_MAP_API = {
            invalidate: function () {
                setTimeout(function () {
                    map.invalidateSize();
                    render();
                }, 50);
            }
        };
    });
})();
