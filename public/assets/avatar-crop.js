/**
 * Circular avatar preview with drag-to-recenter focus point.
 * Expects #avatar-crop-preview img and hidden inputs photo_focus_x / photo_focus_y.
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
        var wrap = document.getElementById('avatar-crop-preview');
        var img = wrap ? wrap.querySelector('img') : null;
        var fileInput = document.getElementById('avatar-file-input');
        var focusX = document.getElementById('photo_focus_x');
        var focusY = document.getElementById('photo_focus_y');
        if (!wrap || !img || !focusX || !focusY) {
            return;
        }

        var dragging = false;
        var objectUrl = null;

        function applyFocus() {
            var x = parseFloat(focusX.value) || 50;
            var y = parseFloat(focusY.value) || 50;
            img.style.objectPosition = x + '% ' + y + '%';
        }

        function setFocusFromEvent(ev) {
            var rect = wrap.getBoundingClientRect();
            var clientX = ev.touches ? ev.touches[0].clientX : ev.clientX;
            var clientY = ev.touches ? ev.touches[0].clientY : ev.clientY;
            var x = ((clientX - rect.left) / rect.width) * 100;
            var y = ((clientY - rect.top) / rect.height) * 100;
            focusX.value = String(Math.max(0, Math.min(100, Math.round(x * 10) / 10)));
            focusY.value = String(Math.max(0, Math.min(100, Math.round(y * 10) / 10)));
            applyFocus();
        }

        function onDown(ev) {
            dragging = true;
            wrap.classList.add('is-dragging');
            setFocusFromEvent(ev);
            ev.preventDefault();
        }
        function onMove(ev) {
            if (!dragging) return;
            setFocusFromEvent(ev);
            ev.preventDefault();
        }
        function onUp() {
            dragging = false;
            wrap.classList.remove('is-dragging');
        }

        wrap.addEventListener('mousedown', onDown);
        wrap.addEventListener('touchstart', onDown, { passive: false });
        window.addEventListener('mousemove', onMove);
        window.addEventListener('touchmove', onMove, { passive: false });
        window.addEventListener('mouseup', onUp);
        window.addEventListener('touchend', onUp);

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                var file = fileInput.files && fileInput.files[0];
                if (!file) return;
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                }
                objectUrl = URL.createObjectURL(file);
                img.src = objectUrl;
                img.hidden = false;
                wrap.classList.add('has-image');
                focusX.value = '50';
                focusY.value = '50';
                applyFocus();
            });
        }

        applyFocus();
    });
})();
