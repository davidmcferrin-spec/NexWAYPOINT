/**
 * Site settings: open/close modals; carrier modal prefill.
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
        function openModal(id) {
            var modal = document.getElementById(id);
            if (!modal) {
                return;
            }
            modal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeModal(modal) {
            if (!modal) {
                return;
            }
            modal.hidden = true;
            if (!document.querySelector('.modal-backdrop:not([hidden])')) {
                document.body.classList.remove('modal-open');
            }
        }

        document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-open-modal');
                if (id === 'carrier-modal') {
                    var title = btn.getAttribute('data-title') || 'Carrier';
                    var type = btn.getAttribute('data-carrier-type') || 'airline';
                    var titleEl = document.getElementById('carrier-modal-title');
                    var typeEl = document.getElementById('carrier-modal-type');
                    var idEl = document.getElementById('carrier-modal-id');
                    var nameEl = document.getElementById('carrier-modal-name');
                    var iataEl = document.getElementById('carrier-modal-iata');
                    var iataLabel = document.getElementById('carrier-modal-iata-label-text');
                    var iataHint = document.getElementById('carrier-modal-iata-hint');
                    if (titleEl) {
                        titleEl.textContent = title;
                    }
                    if (typeEl) {
                        typeEl.value = type;
                    }
                    if (idEl) {
                        idEl.value = btn.getAttribute('data-id') || '0';
                    }
                    if (nameEl) {
                        nameEl.value = btn.getAttribute('data-name') || '';
                    }
                    if (iataEl) {
                        iataEl.value = btn.getAttribute('data-iata') || '';
                        iataEl.required = type === 'airline';
                    }
                    if (iataLabel) {
                        iataLabel.textContent = type === 'rail' ? 'Code (optional)' : 'IATA code';
                    }
                    if (iataHint) {
                        iataHint.textContent = type === 'rail'
                            ? 'Optional (e.g. 2V for Amtrak).'
                            : 'Required for airlines (FlightAware ident).';
                    }
                }
                openModal(id);
            });
        });

        document.querySelectorAll('.modal-backdrop').forEach(function (modal) {
            modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    closeModal(modal);
                });
            });
            modal.addEventListener('click', function (ev) {
                if (ev.target === modal) {
                    closeModal(modal);
                }
            });
        });
    });
})();
