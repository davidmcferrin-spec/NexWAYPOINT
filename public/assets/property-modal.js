/**
 * Standalone "Add hotel property" modal (properties directory page).
 * Opens via [data-open-property-modal], saves via /hotels/api_property.php, reloads on success.
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
        var modal = document.getElementById('property-modal');
        var form = document.getElementById('property-modal-form');
        var errorEl = document.getElementById('property-modal-error');
        if (!modal || !form) {
            return;
        }

        var csrf = modal.getAttribute('data-csrf') || '';

        function openModal() {
            if (errorEl) {
                errorEl.hidden = true;
                errorEl.textContent = '';
            }
            form.reset();
            modal.hidden = false;
            document.body.classList.add('modal-open');
            var first = form.querySelector('input[name="hotel_name"]');
            if (first) {
                first.focus();
            }
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('modal-open');
        }

        document.querySelectorAll('[data-open-property-modal]').forEach(function (btn) {
            btn.addEventListener('click', openModal);
        });

        modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });

        modal.addEventListener('click', function (ev) {
            if (ev.target === modal) {
                closeModal();
            }
        });

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
                if (result.body.warning) {
                    window.alert(result.body.warning);
                }
                window.location.reload();
            }).catch(function (err) {
                if (errorEl) {
                    errorEl.hidden = false;
                    errorEl.textContent = err.message || 'Save failed';
                }
            });
        });
    });
})();
