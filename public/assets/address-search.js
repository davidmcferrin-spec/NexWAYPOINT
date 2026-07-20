/**
 * OpenStreetMap Nominatim address / hotel lookup for property forms.
 * Expects a .address-search root with [data-address-search-input] and [data-address-search-results].
 * Optional [data-address-search-trigger] builds a query from hotel_name + city + state.
 */
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function debounce(fn, ms) {
        var t = null;
        return function () {
            var args = arguments;
            var ctx = this;
            clearTimeout(t);
            t = setTimeout(function () {
                fn.apply(ctx, args);
            }, ms);
        };
    }

    function field(root, name) {
        var form = root.closest('form') || document;
        var prefix = root.getAttribute('data-field-prefix') || '';
        return form.querySelector('[name="' + prefix + name + '"]');
    }

    function setVal(el, value) {
        if (!el) {
            return;
        }
        el.value = value == null ? '' : String(value);
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function bindRoot(root) {
        var input = root.querySelector('[data-address-search-input]');
        var resultsEl = root.querySelector('[data-address-search-results]');
        var statusEl = root.querySelector('[data-address-search-status]');
        var trigger = root.querySelector('[data-address-search-trigger]');
        if (!input || !resultsEl) {
            return;
        }

        var abort = null;

        function setStatus(text) {
            if (statusEl) {
                statusEl.textContent = text || '';
            }
        }

        function clearResults() {
            resultsEl.innerHTML = '';
            resultsEl.hidden = true;
        }

        function applyHit(hit) {
            var nameEl = field(root, 'hotel_name');
            if (nameEl && !String(nameEl.value || '').trim() && hit.hotel_name) {
                setVal(nameEl, hit.hotel_name);
            }
            setVal(field(root, 'address_line1'), hit.address_line1 || '');
            setVal(field(root, 'city'), hit.city || '');
            setVal(field(root, 'state_region'), hit.state_region || '');
            setVal(field(root, 'postal_code'), hit.postal_code || '');
            if (hit.country) {
                setVal(field(root, 'country'), hit.country);
            }
            setVal(field(root, 'latitude'), hit.lat != null ? hit.lat : '');
            setVal(field(root, 'longitude'), hit.lon != null ? hit.lon : '');
            input.value = hit.display_name || '';
            clearResults();
            setStatus('Address filled from OpenStreetMap.');
        }

        function render(results) {
            resultsEl.innerHTML = '';
            if (!results || !results.length) {
                resultsEl.hidden = true;
                setStatus('No matches. Try a fuller street or “Hotel Name, City”.');
                return;
            }
            results.forEach(function (hit) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'address-search-item';
                btn.textContent = hit.display_name;
                btn.addEventListener('click', function () {
                    applyHit(hit);
                });
                resultsEl.appendChild(btn);
            });
            resultsEl.hidden = false;
            setStatus(results.length + ' match' + (results.length === 1 ? '' : 'es') + ' — pick one.');
        }

        function runSearch(q) {
            q = String(q || '').trim();
            if (q.length < 3) {
                clearResults();
                setStatus('');
                return;
            }
            if (abort) {
                abort.abort();
            }
            abort = new AbortController();
            setStatus('Searching…');
            fetch('/hotels/api_address_search.php?q=' + encodeURIComponent(q), {
                credentials: 'same-origin',
                signal: abort.signal,
                headers: { Accept: 'application/json' }
            })
                .then(function (res) {
                    return res.json().then(function (body) {
                        return { ok: res.ok, body: body };
                    });
                })
                .then(function (result) {
                    if (!result.body || !result.body.ok) {
                        throw new Error((result.body && result.body.error) || 'Lookup failed');
                    }
                    render(result.body.results || []);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    clearResults();
                    setStatus(err.message || 'Lookup failed');
                });
        }

        var debounced = debounce(function () {
            runSearch(input.value);
        }, 450);

        input.addEventListener('input', debounced);
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                clearResults();
            }
            if (ev.key === 'Enter') {
                ev.preventDefault();
                runSearch(input.value);
            }
        });

        if (trigger) {
            trigger.addEventListener('click', function () {
                var parts = [];
                var nameEl = field(root, 'hotel_name');
                var cityEl = field(root, 'city');
                var stateEl = field(root, 'state_region');
                if (nameEl && nameEl.value.trim()) {
                    parts.push(nameEl.value.trim());
                }
                if (cityEl && cityEl.value.trim()) {
                    parts.push(cityEl.value.trim());
                }
                if (stateEl && stateEl.value.trim()) {
                    parts.push(stateEl.value.trim());
                }
                var q = parts.join(', ');
                if (!q) {
                    setStatus('Enter a hotel name or city first.');
                    return;
                }
                input.value = q;
                runSearch(q);
            });
        }

        document.addEventListener('click', function (ev) {
            if (!root.contains(ev.target)) {
                clearResults();
            }
        });
    }

    ready(function () {
        document.querySelectorAll('[data-address-search]').forEach(bindRoot);
    });
})();
