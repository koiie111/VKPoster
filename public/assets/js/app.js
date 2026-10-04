// Glue code (CSP-safe, no inline scripts): htmx sends the CSRF token with every request.
document.addEventListener('htmx:configRequest', function (event) {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) {
        event.detail.headers['X-CSRF-Token'] = meta.content;
    }
});

// Double-submit protection for plain forms: after submit, buttons are disabled and marked busy (htmx forms use hx-disabled-elt).
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('hx-post') || form.hasAttribute('data-allow-resubmit') || event.defaultPrevented) {
        return;
    }
    setTimeout(function () {
        form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
            b.disabled = true;
            b.setAttribute('aria-busy', 'true');
        });
    }, 0);
});

// Keep the page usable when the bfcache restores a form whose buttons were disabled by the handler above.
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        document.querySelectorAll('button[aria-busy=true]').forEach(function (b) {
            b.disabled = false;
            b.removeAttribute('aria-busy');
        });
    }
});
