// Runs synchronously in <head> (no defer) so the saved theme is applied before first paint.
(function () {
    try {
        var t = localStorage.getItem('theme');
        if (t === 'light' || t === 'dark') {
            document.documentElement.setAttribute('data-theme', t);
        }
    } catch (e) { /* storage blocked: follow the OS preference */ }
})();
