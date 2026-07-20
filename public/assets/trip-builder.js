/**
 * Spreadsheet-style multi-leg trip builder.
 * Expects #trip-builder-form, #trip-leg-row-template, #trip-builder-initial.
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

        var initial = { legs: [] };
        try {
            initial = JSON.parse(initialEl ? initialEl.textContent : '{}') || initial;
        } catch (e) {
            initial = { legs: [] };
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

            var carrier = row.querySelector('.leg-carrier');
            if (carrier) {
                carrier.addEventListener('change', function () {
                    if (carrier.value === '__new__') {
                        carrierModalSelect = carrier;
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
            var html = tpl.innerHTML.replace(/__IDX__/g, String(nextIndex));
            var wrap = document.createElement('tbody');
            wrap.innerHTML = html.trim();
            var row = wrap.firstElementChild;
            body.appendChild(row);

            var carrier = row.querySelector('.leg-carrier');
            if (carrier && data.carrier_id) {
                carrier.value = String(data.carrier_id);
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
                addRow({});
            });
        }

        // Shared Add New carrier modal (multi-select aware).
        var modal = document.getElementById('carrier-modal');
        var modalForm = document.getElementById('carrier-modal-form');
        var errorEl = document.getElementById('carrier-modal-error');
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
                var payload = {
                    csrf_token: csrf,
                    name: fd.get('name'),
                    iata_code: fd.get('iata_code') || '',
                    carrier_type: 'airline'
                };

                fetch('/flights/api_carrier.php', {
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
                    body.querySelectorAll('.leg-carrier').forEach(function (sel) {
                        var newOpt = document.createElement('option');
                        newOpt.value = String(carrier.id);
                        newOpt.textContent = carrier.label;
                        var addNew = sel.querySelector('option[value="__new__"]');
                        if (addNew) {
                            sel.insertBefore(newOpt, addNew);
                        } else {
                            sel.appendChild(newOpt);
                        }
                    });
                    if (carrierModalSelect) {
                        carrierModalSelect.value = String(carrier.id);
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
