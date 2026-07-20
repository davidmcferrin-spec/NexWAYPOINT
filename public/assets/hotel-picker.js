/**
 * Cascading City/State → property picker + Add New modal for hotel stays.
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
        var root = document.getElementById('hotel-picker');
        if (!root) {
            return;
        }

        var properties = [];
        try {
            properties = JSON.parse(root.getAttribute('data-properties') || '[]');
        } catch (e) {
            properties = [];
        }

        var locationSelect = document.getElementById('location_key');
        var propertySelect = document.getElementById('hotel_property_id');
        var modal = document.getElementById('property-modal');
        var form = document.getElementById('property-modal-form');
        var errorEl = document.getElementById('property-modal-error');
        var csrf = root.getAttribute('data-csrf') || '';
        var selectedId = parseInt(root.getAttribute('data-selected-id') || '0', 10) || 0;

        function propertyOptionLabel(p) {
            var label = p.label || p.hotel_name;
            if (p.overall_rating != null) {
                label += ' (' + Number(p.overall_rating).toFixed(1) + '★)';
            }
            return label;
        }

        function rebuildLocations(preferredKey) {
            var keys = {};
            properties.forEach(function (p) {
                if (p.location_key) {
                    keys[p.location_key] = p.location_label || p.location_key;
                }
            });
            var entries = Object.keys(keys).map(function (k) {
                return { key: k, label: keys[k] };
            });
            entries.sort(function (a, b) {
                return a.label.localeCompare(b.label);
            });

            var current = preferredKey || locationSelect.value;
            locationSelect.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = '— Select location —';
            locationSelect.appendChild(blank);
            entries.forEach(function (entry) {
                var opt = document.createElement('option');
                opt.value = entry.key;
                opt.textContent = entry.label;
                locationSelect.appendChild(opt);
            });
            if (current && keys[current]) {
                locationSelect.value = current;
            }
        }

        function rebuildProperties() {
            var key = locationSelect.value;
            var current = propertySelect.value;
            propertySelect.innerHTML = '';

            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = '— Select property —';
            propertySelect.appendChild(blank);

            if (key) {
                properties.filter(function (p) {
                    return p.location_key === key;
                }).forEach(function (p) {
                    var opt = document.createElement('option');
                    opt.value = String(p.id);
                    opt.textContent = propertyOptionLabel(p);
                    propertySelect.appendChild(opt);
                });
            }

            var addNew = document.createElement('option');
            addNew.value = '__new__';
            addNew.textContent = '— Add New… —';
            propertySelect.appendChild(addNew);

            if (current && current !== '__new__' && Array.prototype.some.call(propertySelect.options, function (o) {
                return o.value === current;
            })) {
                propertySelect.value = current;
            }
        }

        function openModal() {
            if (errorEl) {
                errorEl.hidden = true;
                errorEl.textContent = '';
            }
            if (form) {
                form.reset();
            }
            modal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('modal-open');
            if (propertySelect.value === '__new__') {
                propertySelect.value = '';
            }
        }

        locationSelect.addEventListener('change', rebuildProperties);

        propertySelect.addEventListener('change', function () {
            if (propertySelect.value === '__new__') {
                openModal();
            }
        });

        modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });

        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (errorEl) {
                    errorEl.hidden = true;
                }
                var fd = new FormData(form);
                var payload = { action: 'create', csrf_token: csrf };
                fd.forEach(function (value, key) {
                    payload[key] = value;
                });

                fetch('/hotels/api_property.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.json().then(function (body) {
                        return { ok: res.ok, body: body };
                    });
                }).then(function (result) {
                    if (!result.body || !result.body.ok) {
                        throw new Error((result.body && result.body.error) || 'Could not create property');
                    }
                    var p = result.body.property;
                    properties.push(p);
                    rebuildLocations(p.location_key);
                    locationSelect.value = p.location_key;
                    rebuildProperties();
                    propertySelect.value = String(p.id);
                    closeModal();
                    if (result.body.warning) {
                        window.alert(result.body.warning);
                    }
                }).catch(function (err) {
                    if (errorEl) {
                        errorEl.hidden = false;
                        errorEl.textContent = err.message || 'Save failed';
                    }
                });
            });
        }

        // Initial selection from server (e.g. ?property_id=)
        if (selectedId > 0) {
            var match = properties.find(function (p) {
                return Number(p.id) === selectedId;
            });
            if (match && match.location_key) {
                rebuildLocations(match.location_key);
                locationSelect.value = match.location_key;
                rebuildProperties();
                propertySelect.value = String(match.id);
                return;
            }
        }

        rebuildLocations(locationSelect.getAttribute('data-initial') || '');
        rebuildProperties();
    });
})();
