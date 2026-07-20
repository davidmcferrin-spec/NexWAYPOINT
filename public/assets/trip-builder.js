/**
 * Spreadsheet-style multi-leg trip builder (flight + train) and hotel attachments.
 */
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function parseLocal(value) {
        if (!value) {
            return null;
        }
        var d = new Date(value);
        return isNaN(d.getTime()) ? null : d;
    }

    function formatGapHours(ms) {
        var hours = ms / 3600000;
        if (hours < 1) {
            return Math.round(hours * 60) + 'm';
        }
        var h = Math.floor(hours);
        var m = Math.round((hours - h) * 60);
        return m > 0 ? h + 'h ' + m + 'm' : h + 'h';
    }

    ready(function () {
        var form = document.getElementById('trip-builder-form');
        var body = document.getElementById('trip-legs-body');
        var tpl = document.getElementById('trip-leg-row-template');
        var addBtn = document.getElementById('trip-leg-add');
        var initialEl = document.getElementById('trip-builder-initial');
        if (!form || !body || !tpl) {
            return;
        }

        var layoverHours = parseFloat(form.getAttribute('data-layover-hours') || '3') || 3;
        var nextIndex = 0;
        var carrierModalSelect = null;
        var carrierModalMode = 'flight';

        var initial = { legs: [], airlines: [], rail: [], hotels: [], attachable: [] };
        try {
            initial = JSON.parse(initialEl ? initialEl.textContent : '{}') || initial;
        } catch (e) {
            initial = { legs: [], airlines: [], rail: [], hotels: [], attachable: [] };
        }

        var airlines = initial.airlines || [];
        var rail = initial.rail || [];
        var attachedHotels = (initial.hotels || []).slice();

        function catalogForMode(mode) {
            return mode === 'train' ? rail : airlines;
        }

        function fillCarrierSelect(select, mode, selectedId) {
            var catalog = catalogForMode(mode);
            var html = '<option value="">—</option>';
            catalog.forEach(function (c) {
                html += '<option value="' + c.id + '">' + escapeHtml(c.label) + '</option>';
            });
            html += '<option value="__new__">— Add New… —</option>';
            select.innerHTML = html;
            if (selectedId) {
                select.value = String(selectedId);
            }
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function updateModeLabels(row) {
            var mode = row.querySelector('.leg-mode');
            var flight = row.querySelector('.leg-flight');
            if (!mode || !flight) {
                return;
            }
            if (mode.value === 'train') {
                flight.placeholder = '90';
                flight.removeAttribute('inputmode');
            } else {
                flight.placeholder = '1234';
                flight.setAttribute('inputmode', 'numeric');
            }
        }

        function reindex() {
            var rows = body.querySelectorAll('.trip-leg-row');
            rows.forEach(function (row, idx) {
                row.querySelectorAll('[name]').forEach(function (el) {
                    el.name = el.name.replace(/legs\[\d+\]/, 'legs[' + idx + ']');
                });
            });
            nextIndex = rows.length;
            updateGapHints();
            var heading = document.querySelector('.leg-num-heading');
            if (heading) {
                var anyTrain = false;
                rows.forEach(function (row) {
                    var m = row.querySelector('.leg-mode');
                    if (m && m.value === 'train') {
                        anyTrain = true;
                    }
                });
                heading.textContent = anyTrain ? 'Number' : 'Flight #';
            }
        }

        function updateGapHints() {
            var rows = Array.prototype.slice.call(body.querySelectorAll('.trip-leg-row'));
            rows.forEach(function (row, idx) {
                var hint = row.querySelector('.leg-gap-hint');
                if (!hint) {
                    return;
                }
                if (idx === 0) {
                    hint.textContent = '';
                    hint.className = 'leg-gap-hint';
                    return;
                }
                var prev = rows[idx - 1];
                var arrive = parseLocal(prev.querySelector('.leg-arrive').value);
                var depart = parseLocal(row.querySelector('.leg-depart').value);
                if (!arrive || !depart || depart <= arrive) {
                    hint.textContent = '';
                    hint.className = 'leg-gap-hint';
                    return;
                }
                var ms = depart.getTime() - arrive.getTime();
                var hours = ms / 3600000;
                if (hours <= layoverHours) {
                    hint.textContent = 'Connection · ' + formatGapHours(ms);
                    hint.className = 'leg-gap-hint is-connection';
                } else {
                    hint.textContent = 'Stay · ' + formatGapHours(ms);
                    hint.className = 'leg-gap-hint is-stay';
                }
            });
        }

        function bindRow(row) {
            row.querySelectorAll('.leg-arrive, .leg-depart').forEach(function (input) {
                input.addEventListener('change', updateGapHints);
                input.addEventListener('input', updateGapHints);
            });

            var mode = row.querySelector('.leg-mode');
            var carrier = row.querySelector('.leg-carrier');
            if (mode && carrier) {
                mode.addEventListener('change', function () {
                    fillCarrierSelect(carrier, mode.value, null);
                    updateModeLabels(row);
                    reindex();
                });
            }

            if (carrier) {
                carrier.addEventListener('change', function () {
                    if (carrier.value === '__new__') {
                        carrierModalSelect = carrier;
                        carrierModalMode = mode ? mode.value : 'flight';
                        openCarrierModal();
                    }
                });
            }

            var up = row.querySelector('.leg-move-up');
            var down = row.querySelector('.leg-move-down');
            var remove = row.querySelector('.leg-remove');
            if (up) {
                up.addEventListener('click', function () {
                    var prev = row.previousElementSibling;
                    if (prev) {
                        body.insertBefore(row, prev);
                        reindex();
                    }
                });
            }
            if (down) {
                down.addEventListener('click', function () {
                    var next = row.nextElementSibling;
                    if (next) {
                        body.insertBefore(next, row);
                        reindex();
                    }
                });
            }
            if (remove) {
                remove.addEventListener('click', function () {
                    if (body.querySelectorAll('.trip-leg-row').length <= 1) {
                        return;
                    }
                    row.remove();
                    reindex();
                });
            }
        }

        function addRow(data) {
            data = data || {};
            var mode = data.segment_type === 'train' ? 'train' : 'flight';
            var html = tpl.innerHTML.replace(/__IDX__/g, String(nextIndex));
            var wrap = document.createElement('tbody');
            wrap.innerHTML = html.trim();
            var row = wrap.firstElementChild;
            body.appendChild(row);

            var modeEl = row.querySelector('.leg-mode');
            if (modeEl) {
                modeEl.value = mode;
            }
            var carrier = row.querySelector('.leg-carrier');
            if (carrier) {
                fillCarrierSelect(carrier, mode, data.carrier_id || null);
            }
            var flight = row.querySelector('.leg-flight');
            if (flight) {
                flight.value = data.flight_number || '';
            }
            var origin = row.querySelector('.leg-origin');
            if (origin) {
                origin.value = data.origin || '';
            }
            var dest = row.querySelector('.leg-dest');
            if (dest) {
                dest.value = data.destination || '';
            }
            var depart = row.querySelector('.leg-depart');
            if (depart) {
                depart.value = data.depart_dt || '';
            }
            var arrive = row.querySelector('.leg-arrive');
            if (arrive) {
                arrive.value = data.arrive_dt || '';
            }
            var conf = row.querySelector('.leg-conf');
            if (conf) {
                conf.value = data.confirmation_code || '';
            }

            updateModeLabels(row);
            bindRow(row);
            nextIndex += 1;
            reindex();
            return row;
        }

        (initial.legs && initial.legs.length ? initial.legs : [{}]).forEach(function (leg) {
            addRow(leg);
        });

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                addRow({ segment_type: 'flight' });
            });
        }

        // --- Hotels ---
        var hotelsList = document.getElementById('trip-hotels-attached');
        var hotelsHidden = document.getElementById('trip-hotels-hidden');
        var attachSelect = document.getElementById('trip-hotel-attach-select');
        var attachBtn = document.getElementById('trip-hotel-attach-btn');
        var newProperty = document.getElementById('trip-hotel-new-property');
        var newStart = document.getElementById('trip-hotel-new-start');
        var newEnd = document.getElementById('trip-hotel-new-end');
        var newBtn = document.getElementById('trip-hotel-new-btn');
        var newHotelsIndex = 0;

        function renderHotels() {
            if (!hotelsList || !hotelsHidden) {
                return;
            }
            hotelsList.innerHTML = '';
            hotelsHidden.innerHTML = '';
            if (attachedHotels.length === 0) {
                hotelsList.innerHTML = '<p class="hint">No hotels linked yet.</p>';
            }
            attachedHotels.forEach(function (h, idx) {
                var row = document.createElement('div');
                row.className = 'trip-hotel-row';
                row.innerHTML = '<span>' + escapeHtml(h.label)
                    + (h.city ? ' · ' + escapeHtml(h.city) : '')
                    + ' · ' + escapeHtml(h.stay_start) + ' → ' + escapeHtml(h.stay_end)
                    + '</span>'
                    + '<button type="button" class="linkish" data-hotel-remove="' + idx + '">Remove</button>';
                hotelsList.appendChild(row);

                if (h.stay_id) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'hotels[' + idx + '][stay_id]';
                    input.value = String(h.stay_id);
                    hotelsHidden.appendChild(input);
                } else if (h._new) {
                    var p = document.createElement('input');
                    p.type = 'hidden';
                    p.name = 'hotels_new[' + h._newIndex + '][property_id]';
                    p.value = String(h.property_id);
                    hotelsHidden.appendChild(p);
                    var s = document.createElement('input');
                    s.type = 'hidden';
                    s.name = 'hotels_new[' + h._newIndex + '][stay_start]';
                    s.value = h.stay_start;
                    hotelsHidden.appendChild(s);
                    var e = document.createElement('input');
                    e.type = 'hidden';
                    e.name = 'hotels_new[' + h._newIndex + '][stay_end]';
                    e.value = h.stay_end;
                    hotelsHidden.appendChild(e);
                }
            });

            hotelsList.querySelectorAll('[data-hotel-remove]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var i = parseInt(btn.getAttribute('data-hotel-remove'), 10);
                    var removed = attachedHotels.splice(i, 1)[0];
                    if (removed && removed.stay_id && attachSelect) {
                        var opt = document.createElement('option');
                        opt.value = String(removed.stay_id);
                        opt.textContent = removed.label
                            + ' · ' + removed.stay_start + ' → ' + removed.stay_end
                            + (removed.city ? ' · ' + removed.city : '');
                        attachSelect.appendChild(opt);
                    }
                    renderHotels();
                });
            });
        }

        renderHotels();

        if (attachBtn && attachSelect) {
            attachBtn.addEventListener('click', function () {
                var id = parseInt(attachSelect.value, 10);
                if (!id) {
                    return;
                }
                var opt = attachSelect.options[attachSelect.selectedIndex];
                var label = opt ? opt.textContent : 'Hotel';
                attachedHotels.push({
                    stay_id: id,
                    label: label.split(' · ')[0],
                    city: '',
                    stay_start: '',
                    stay_end: '',
                    _labelFull: label
                });
                // Prefer parsing dates from option text: Name · start → end · city
                var parts = label.split(' · ');
                if (parts.length >= 2 && parts[1].indexOf('→') !== -1) {
                    var range = parts[1].split('→');
                    attachedHotels[attachedHotels.length - 1].label = parts[0];
                    attachedHotels[attachedHotels.length - 1].stay_start = range[0].trim();
                    attachedHotels[attachedHotels.length - 1].stay_end = range[1].trim();
                    if (parts[2]) {
                        attachedHotels[attachedHotels.length - 1].city = parts[2];
                    }
                }
                opt.remove();
                attachSelect.value = '';
                renderHotels();
            });
        }

        if (newBtn && newProperty && newStart && newEnd) {
            newBtn.addEventListener('click', function () {
                var pid = parseInt(newProperty.value, 10);
                var start = newStart.value;
                var end = newEnd.value;
                if (!pid || !start || !end) {
                    return;
                }
                if (end < start) {
                    return;
                }
                var propLabel = newProperty.options[newProperty.selectedIndex].textContent || 'Hotel';
                attachedHotels.push({
                    stay_id: null,
                    property_id: pid,
                    label: propLabel.split(' · ')[0],
                    city: propLabel.indexOf(' · ') !== -1 ? propLabel.split(' · ').slice(1).join(' · ') : '',
                    stay_start: start,
                    stay_end: end,
                    _new: true,
                    _newIndex: newHotelsIndex++
                });
                newProperty.value = '';
                newStart.value = '';
                newEnd.value = '';
                renderHotels();
            });
        }

        // Shared Add New carrier / rail modal.
        var modal = document.getElementById('carrier-modal');
        var modalForm = document.getElementById('carrier-modal-form');
        var errorEl = document.getElementById('carrier-modal-error');
        var titleEl = document.getElementById('carrier-modal-title');
        var nameLabel = document.getElementById('carrier-modal-name-label');
        var iataWrap = document.getElementById('carrier-modal-iata-wrap');
        var hintEl = document.getElementById('carrier-modal-hint');
        var csrfInput = form.querySelector('input[name="csrf_token"]');
        var csrf = csrfInput ? csrfInput.value : '';

        function openCarrierModal() {
            if (!modal || !modalForm) {
                return;
            }
            if (errorEl) {
                errorEl.hidden = true;
                errorEl.textContent = '';
            }
            modalForm.reset();
            var isRail = carrierModalMode === 'train';
            if (titleEl) {
                titleEl.textContent = isRail ? 'Add rail operator' : 'Add carrier';
            }
            if (nameLabel) {
                nameLabel.childNodes[0].textContent = isRail ? 'Operator name' : 'Airline name';
            }
            if (iataWrap) {
                iataWrap.hidden = isRail;
                var iataInput = iataWrap.querySelector('input[name="iata_code"]');
                if (iataInput) {
                    iataInput.required = !isRail;
                }
            }
            if (hintEl) {
                hintEl.textContent = isRail
                    ? 'Rail operators do not need an IATA code.'
                    : 'IATA is used with the flight number for FlightAware lookups.';
            }
            modal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeCarrierModal() {
            if (!modal) {
                return;
            }
            modal.hidden = true;
            document.body.classList.remove('modal-open');
            if (carrierModalSelect && carrierModalSelect.value === '__new__') {
                carrierModalSelect.value = '';
            }
            carrierModalSelect = null;
        }

        if (modal) {
            modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
                btn.addEventListener('click', closeCarrierModal);
            });
        }

        if (modalForm) {
            modalForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var fd = new FormData(modalForm);
                var isRail = carrierModalMode === 'train';
                var payload = {
                    csrf_token: csrf,
                    name: fd.get('name'),
                    iata_code: isRail ? '' : (fd.get('iata_code') || ''),
                    carrier_type: isRail ? 'rail' : 'airline'
                };
                var api = isRail ? '/trains/api_operator.php' : '/flights/api_carrier.php';

                fetch(api, {
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
                        throw new Error((result.body && result.body.error) || 'Could not create carrier');
                    }
                    var carrier = result.body.carrier;
                    var entry = { id: carrier.id, label: carrier.label };
                    if (isRail) {
                        rail.push(entry);
                    } else {
                        airlines.push(entry);
                    }
                    body.querySelectorAll('.trip-leg-row').forEach(function (row) {
                        var m = row.querySelector('.leg-mode');
                        var sel = row.querySelector('.leg-carrier');
                        if (!m || !sel) {
                            return;
                        }
                        if ((isRail && m.value === 'train') || (!isRail && m.value === 'flight')) {
                            var cur = sel.value;
                            fillCarrierSelect(sel, m.value, cur === '__new__' ? null : cur);
                        }
                    });
                    if (carrierModalSelect) {
                        fillCarrierSelect(
                            carrierModalSelect,
                            carrierModalMode,
                            carrier.id
                        );
                    }
                    closeCarrierModal();
                }).catch(function (err) {
                    if (errorEl) {
                        errorEl.hidden = false;
                        errorEl.textContent = err.message || 'Error';
                    }
                });
            });
        }
    });
})();
