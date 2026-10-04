// Calendar drag and drop (CSP-safe: no inline scripts). Planned posts are draggable links ([data-post-id]); every day cell has
// [data-day]. Dropping a post on another day asks the server to move it (the server keeps the time of day and validates the post
// again for every channel) and reloads the page on success. Keyboard and touch users open the post and change the time there.
(function () {
    'use strict';

    function calendarDnd() {
        return {
            init: function () {
                var root = this.$el;
                if (root.getAttribute('data-enabled') !== '1') { return; }
                var base = root.getAttribute('data-move-base');
                var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                var dragged = null;
                var busy = false;

                function clearOver() {
                    Array.prototype.forEach.call(root.querySelectorAll('[data-over]'), function (n) { n.removeAttribute('data-over'); n.classList.remove('ring-2', 'ring-primary'); });
                }

                function toast(text, kind) { if (window.toast) { window.toast(text, kind); } }

                function move(postId, day) {
                    busy = true;
                    root.setAttribute('aria-busy', 'true');
                    fetch(base + '/' + encodeURIComponent(postId) + '/move', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
                        body: JSON.stringify({ date: day }),
                    }).then(function (r) {
                        return r.json().catch(function () { return {}; }).then(function (data) { return { status: r.status, data: data }; });
                    }).then(function (result) {
                        if (result.data && result.data.ok) {
                            try { window.sessionStorage.setItem('calendar.toast', 'Пост перенесён на ' + result.data.label + '.'); } catch (e) { /* ignore */ }
                            window.location.reload();
                            return;
                        }
                        var message = (result.data && result.data.message) || (result.status === 419 ? 'Страница устарела. Обновите её и попробуйте ещё раз.' : 'Не удалось перенести пост.');
                        toast(message, 'error');
                    }).catch(function () {
                        toast('Нет связи с сервером. Проверьте интернет и попробуйте ещё раз.', 'error');
                    }).then(function () {
                        busy = false;
                        root.removeAttribute('aria-busy');
                    });
                }

                root.addEventListener('dragstart', function (e) {
                    var item = e.target.closest ? e.target.closest('[data-post-id]') : null;
                    if (!item || busy) { return; }
                    dragged = item;
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', item.getAttribute('data-post-id'));
                    item.classList.add('opacity-50');
                });
                root.addEventListener('dragend', function () {
                    if (dragged) { dragged.classList.remove('opacity-50'); }
                    dragged = null;
                    clearOver();
                });
                root.addEventListener('dragover', function (e) {
                    if (!dragged) { return; }
                    var day = e.target.closest ? e.target.closest('[data-day]') : null;
                    if (!day) { return; }
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    clearOver();
                    day.setAttribute('data-over', 'true');
                    day.classList.add('ring-2', 'ring-primary');
                });
                root.addEventListener('drop', function (e) {
                    if (!dragged) { return; }
                    var day = e.target.closest ? e.target.closest('[data-day]') : null;
                    e.preventDefault();
                    var postId = dragged.getAttribute('data-post-id');
                    dragged.classList.remove('opacity-50');
                    dragged = null;
                    clearOver();
                    if (day && postId) { move(postId, day.getAttribute('data-day')); }
                });
            },
        };
    }

    // A toast that was queued before the reload (the page itself cannot show it before it is reloaded).
    document.addEventListener('alpine:init', function () {
        window.Alpine.data('calendarDnd', calendarDnd);
        try {
            var text = window.sessionStorage.getItem('calendar.toast');
            if (text) {
                window.sessionStorage.removeItem('calendar.toast');
                window.setTimeout(function () { if (window.toast) { window.toast(text, 'success'); } }, 300);
            }
        } catch (e) { /* ignore */ }
    });
})();
