// Admin → Дизайн и цвета: live preview of the colours being edited, and "back to the standard colour" buttons.
// The preview boxes get the variables through the CSSOM (allowed by the CSP, unlike inline style attributes).
(function () {
    function triplet(hex) {
        var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
        return m ? parseInt(m[1], 16) + ' ' + parseInt(m[2], 16) + ' ' + parseInt(m[3], 16) : null;
    }

    function apply(form) {
        form.querySelectorAll('[data-theme-preview]').forEach(function (box) {
            var mode = box.getAttribute('data-theme-preview');
            form.querySelectorAll('input[type=color][name^="' + mode + '["]').forEach(function (input) {
                var value = triplet(input.value);
                if (value) {
                    box.style.setProperty('--' + input.getAttribute('data-token'), value);
                }
            });
        });
    }

    function sync(input) {
        var row = input.closest('[data-color-row]');
        if (!row) {
            return;
        }
        var hex = row.querySelector('[data-color-hex]');
        if (hex) {
            hex.textContent = input.value;
        }
        var reset = row.querySelector('[data-color-default]');
        if (reset) {
            reset.classList.toggle('invisible', input.value.toLowerCase() === (input.getAttribute('data-default') || '').toLowerCase());
        }
    }

    document.querySelectorAll('[data-design-form]').forEach(function (form) {
        apply(form);
        form.addEventListener('input', function (event) {
            if (event.target instanceof HTMLInputElement && event.target.type === 'color') {
                sync(event.target);
                apply(form);
            }
        });
        form.addEventListener('click', function (event) {
            var button = event.target instanceof Element ? event.target.closest('[data-color-default]') : null;
            if (!button) {
                return;
            }
            var input = button.closest('[data-color-row]').querySelector('input[type=color]');
            input.value = input.getAttribute('data-default');
            sync(input);
            apply(form);
        });
    });
})();
