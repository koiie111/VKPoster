// "Waiting for the channel" widget of the Telegram connect page (CSP-safe: no inline scripts, no innerHTML).
// It asks the status URL every few seconds; the server answers JSON {state, message, channel}.
// Markup contract: docs/architecture/channels.md.
(function () {
    'use strict';

    function channelWait() {
        return {
            init: function () {
                var root = this.$el;
                var url = root.getAttribute('data-status-url');
                var done = root.getAttribute('data-done-url');
                var text = root.querySelector('[data-wait-text]');
                var spinner = root.querySelector('[data-wait-spinner]');
                if (!url || !text) { return; }
                var timer = null;
                var stopped = false;

                function show(message, tone) {
                    text.textContent = message;
                    root.setAttribute('data-tone', tone);
                }

                function stop() {
                    stopped = true;
                    if (timer) { window.clearTimeout(timer); }
                    if (spinner) { spinner.hidden = true; }
                }

                function poll() {
                    if (stopped) { return; }
                    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                        .then(function (data) {
                            if (data.state === 'connected') {
                                stop();
                                show('Готово! Канал «' + (data.channel || '') + '» подключён.', 'ok');
                                window.setTimeout(function () { window.location.href = done; }, 1200);
                                return;
                            }
                            if (data.state === 'expired') {
                                stop();
                                show('Код устарел. Получите новый и повторите.', 'warn');
                                window.setTimeout(function () { window.location.reload(); }, 2500);
                                return;
                            }
                            if (data.state === 'problem') {
                                show(data.message, 'warn');
                            } else {
                                show('Ждём сообщение в канале…', 'wait');
                            }
                            timer = window.setTimeout(poll, 3000);
                        })
                        .catch(function () {
                            show('Не получается проверить подключение. Попробуйте обновить страницу.', 'warn');
                            timer = window.setTimeout(poll, 8000);
                        });
                }

                timer = window.setTimeout(poll, 2000);
            },
        };
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.data('channelWait', channelWait);
    });
})();
