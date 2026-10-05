// Cookie notice: remembers the choice in a plain cookie (it is a preference, not a secret) and hides the banner.
(function () {
    'use strict';
    var banner = document.querySelector('[data-cookie-banner]');
    if (!banner) { return; }
    banner.addEventListener('click', function (event) {
        var button = event.target.closest('[data-cookie-choice]');
        if (!button) { return; }
        var value = button.getAttribute('data-cookie-choice') === 'all' ? 'all' : 'necessary';
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'cookie_consent=' + value + '; Max-Age=15552000; Path=/; SameSite=Lax' + secure;
        banner.remove();
    });
})();
