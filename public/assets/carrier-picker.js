/**
 * Carrier dropdown + Add New modal for flight entry.
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
        var select = document.getElementById('carrier_id');
        var modal = document.getElementById('carrier-modal');
        var form = document.getElementById('carrier-modal-form');
        var errorEl = document.getElementById('carrier-modal-error');
        if (!select || !modal || !form) {
            return;
        }

        var csrfInput = document.querySelector('input[name="csrf_token"]');
        var csrf = csrfInput ? csrfInput.value : '';

        function openModal() {
            if (errorEl) {
                errorEl.hidden = true;
                errorEl.textContent = '';
            }
            form.reset();
            modal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('modal-open');
            if (select.value === '__new__') {
                select.value = '';
            }
        }

        select.addEventListener('change', function () {
            if (select.value === '__new__') {
                openModal();
            }
        });

        modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var fd = new FormData(form);
            var payload = {
                csrf_token: csrf,
                name: fd.get('name'),
                iata_code: fd.get('iata_code')
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
                var c = result.body.carrier;
                var opt = document.createElement('option');
                opt.value = String(c.id);
                opt.textContent = c.label;
                // Insert before Add New
                var addNew = select.querySelector('option[value="__new__"]');
                select.insertBefore(opt, addNew);
                select.value = String(c.id);
                closeModal();
            }).catch(function (err) {
                if (errorEl) {
                    errorEl.hidden = false;
                    errorEl.textContent = err.message || 'Save failed';
                }
            });
        });
    });
})();
