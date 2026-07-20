/**
 * Site settings: open/close modals; carrier + venue modal prefill.
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

        function fillVenueModal(btn) {
            var title = (btn && btn.getAttribute('data-title')) || 'Add office / venue';
            var id = (btn && btn.getAttribute('data-id')) || '0';
            var isEdit = id !== '0' && id !== '';
            var titleEl = document.getElementById('venue-modal-title');
            var actionEl = document.getElementById('venue-modal-action');
            var idEl = document.getElementById('venue-modal-id');
            var nameEl = document.getElementById('venue-modal-name');
            var addressEl = document.getElementById('venue-modal-address');
            var cityEl = document.getElementById('venue-modal-city');
            var stateEl = document.getElementById('venue-modal-state');
            var postalEl = document.getElementById('venue-modal-postal');
            var countryEl = document.getElementById('venue-modal-country');
            var notesEl = document.getElementById('venue-modal-notes');
            var activeWrap = document.getElementById('venue-modal-active-wrap');
            var activeEl = document.getElementById('venue-modal-active');
            var submitEl = document.getElementById('venue-modal-submit');

            if (titleEl) {
                titleEl.textContent = title;
            }
            if (actionEl) {
                actionEl.value = isEdit ? 'update_venue' : 'add_venue';
            }
            if (idEl) {
                idEl.value = isEdit ? id : '0';
            }
            if (nameEl) {
                nameEl.value = (btn && btn.getAttribute('data-name')) || '';
            }
            if (addressEl) {
                addressEl.value = (btn && btn.getAttribute('data-address')) || '';
            }
            if (cityEl) {
                cityEl.value = (btn && btn.getAttribute('data-city')) || '';
            }
            if (stateEl) {
                stateEl.value = (btn && btn.getAttribute('data-state')) || '';
            }
            if (postalEl) {
                postalEl.value = (btn && btn.getAttribute('data-postal')) || '';
            }
            if (countryEl) {
                countryEl.value = (btn && btn.getAttribute('data-country')) || 'USA';
            }
            if (notesEl) {
                notesEl.value = (btn && btn.getAttribute('data-notes')) || '';
            }
            if (activeWrap && activeEl) {
                activeWrap.hidden = !isEdit;
                activeEl.checked = !btn || btn.getAttribute('data-active') !== '0';
            }
            if (submitEl) {
                submitEl.textContent = isEdit ? 'Save venue' : 'Add venue';
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
                if (id === 'venue-modal') {
                    fillVenueModal(btn);
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

        // Deep-link / map "Edit" → open venue modal prefilled from server.
        var venueModal = document.getElementById('venue-modal');
        if (venueModal && venueModal.getAttribute('data-autoload') === '1') {
            var idEl = document.getElementById('venue-modal-id');
            var actionEl = document.getElementById('venue-modal-action');
            var activeWrap = document.getElementById('venue-modal-active-wrap');
            var titleEl = document.getElementById('venue-modal-title');
            var submitEl = document.getElementById('venue-modal-submit');
            if (actionEl) {
                actionEl.value = 'update_venue';
            }
            if (activeWrap) {
                activeWrap.hidden = false;
            }
            if (titleEl) {
                titleEl.textContent = 'Edit office / venue';
            }
            if (submitEl) {
                submitEl.textContent = 'Save venue';
            }
            if (idEl && idEl.value && idEl.value !== '0') {
                openModal('venue-modal');
            }
        }
    });
})();
