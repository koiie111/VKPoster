// Media library uploader (CSP-safe: no inline scripts, no innerHTML). Files are sent one by one with XHR so that
// each has its own progress bar; the server answers JSON {ok, items, errors}. Markup contract: docs/architecture/media.md.
(function () {
    'use strict';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function mediaUploader() {
        return {
            init: function () {
                var root = this.$el;
                var input = root.querySelector('[data-upload-input]');
                var zone = root.querySelector('[data-dropzone]');
                var list = root.querySelector('[data-upload-list]');
                var summary = root.querySelector('[data-upload-summary]');
                if (!input || !zone || !list) { return; }
                var action = root.getAttribute('data-upload-url');
                var folder = root.getAttribute('data-folder') || '';
                var max = parseInt(root.getAttribute('data-max-bytes') || '0', 10);
                var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                var queue = [];
                var running = 0;
                var done = 0;
                var failed = 0;
                var total = 0;

                var say = function () {
                    if (!summary) { return; }
                    summary.textContent = total === 0 ? '' : 'Загружено ' + done + ' из ' + total + (failed ? ', с ошибкой: ' + failed : '');
                };

                var finishAll = function () {
                    if (done + failed < total) { return; }
                    if (done > 0 && failed === 0) {
                        window.location.reload();
                        return;
                    }
                    if (done > 0) {
                        var again = el('a', 'btn btn-secondary btn-sm mt-3', 'Показать загруженные');
                        again.href = window.location.href;
                        list.appendChild(again);
                    }
                };

                var next = function () {
                    while (running < 3 && queue.length) {
                        send(queue.shift());
                    }
                };

                var send = function (job) {
                    running += 1;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', action);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-CSRF-Token', token);
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) { job.bar.value = Math.round(e.loaded * 100 / e.total); }
                    });
                    var end = function (ok, message) {
                        running -= 1;
                        job.bar.hidden = true;
                        job.status.textContent = message;
                        job.status.className = ok ? 'text-sm text-ok-fg' : 'text-sm font-medium text-bad-fg';
                        if (!ok) { job.status.setAttribute('role', 'alert'); }
                        if (ok) { done += 1; } else { failed += 1; }
                        say();
                        next();
                        finishAll();
                    };
                    xhr.addEventListener('load', function () {
                        var data = null;
                        try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                        if (xhr.status === 419) { end(false, 'Страница устарела. Обновите её и попробуйте ещё раз.'); return; }
                        if (xhr.status === 429) { end(false, 'Слишком много загрузок подряд. Подождите минуту.'); return; }
                        if (xhr.status === 413) { end(false, 'Файл слишком большой.'); return; }
                        if (data && data.ok) {
                            var item = data.items && data.items[0];
                            end(true, item && item.duplicate ? 'Такой файл уже был в медиатеке' : 'Готово');
                        } else if (data && data.errors && data.errors.length) {
                            end(false, String(data.errors[0]).replace(/^[^:]*:\s*/, ''));
                        } else {
                            end(false, 'Не удалось загрузить файл. Попробуйте ещё раз.');
                        }
                    });
                    xhr.addEventListener('error', function () { end(false, 'Нет связи с сервером. Проверьте интернет и попробуйте снова.'); });
                    var form = new FormData();
                    form.append('file', job.file);
                    form.append('folder', folder);
                    xhr.send(form);
                };

                var add = function (files) {
                    // A new selection starts a new batch: forget the finished one (its errors were already shown).
                    if (running === 0 && queue.length === 0) {
                        while (list.firstChild) { list.removeChild(list.firstChild); }
                        total = 0; done = 0; failed = 0;
                    }
                    Array.prototype.forEach.call(files, function (file) {
                        total += 1;
                        var row = el('li', 'rounded-ctl border border-line bg-surface p-3');
                        var head = el('div', 'flex items-center justify-between gap-3');
                        head.appendChild(el('span', 'min-w-0 break-words text-sm font-medium', file.name));
                        var status = el('span', 'shrink-0 text-sm text-muted', 'В очереди');
                        head.appendChild(status);
                        var bar = el('progress', 'progress mt-2');
                        bar.max = 100;
                        bar.value = 0;
                        bar.setAttribute('aria-label', 'Загрузка: ' + file.name);
                        row.appendChild(head);
                        row.appendChild(bar);
                        list.appendChild(row);
                        if (max > 0 && file.size > max) {
                            bar.hidden = true;
                            status.textContent = 'Файл больше ' + Math.round(max / 1048576) + ' МБ';
                            status.className = 'shrink-0 text-sm font-medium text-bad-fg';
                            status.setAttribute('role', 'alert');
                            failed += 1;
                            return;
                        }
                        queue.push({ file: file, bar: bar, status: status });
                    });
                    say();
                    next();
                    finishAll();
                };

                input.addEventListener('change', function () { add(input.files); input.value = ''; });
                ['dragenter', 'dragover'].forEach(function (ev) {
                    zone.addEventListener(ev, function (e) { e.preventDefault(); zone.setAttribute('data-over', 'true'); });
                });
                ['dragleave', 'drop'].forEach(function (ev) {
                    zone.addEventListener(ev, function () { zone.removeAttribute('data-over'); });
                });
                zone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    if (e.dataTransfer && e.dataTransfer.files.length) { add(e.dataTransfer.files); }
                });
            },
        };
    }

    /** Watermark settings: the preview image follows the form fields (range inputs and position radios). */
    function watermarkPreview() {
        return {
            init: function () {
                var root = this.$el;
                var img = root.querySelector('[data-preview-img]');
                var base = root.getAttribute('data-preview-url');
                if (!img || !base) { return; }
                var timer = null;
                var update = function () {
                    var params = new URLSearchParams();
                    ['opacity', 'scale', 'margin'].forEach(function (name) {
                        var field = root.querySelector('[name="' + name + '"]');
                        if (field) { params.set(name, field.value); }
                    });
                    var pos = root.querySelector('[name="position"]:checked');
                    if (pos) { params.set('position', pos.value); }
                    img.src = base + '?' + params.toString();
                    Array.prototype.forEach.call(root.querySelectorAll('[data-range-out]'), function (out) {
                        var field = root.querySelector('[name="' + out.getAttribute('data-range-out') + '"]');
                        if (field) { out.textContent = field.value + ' %'; }
                    });
                };
                root.addEventListener('input', function () { window.clearTimeout(timer); timer = window.setTimeout(update, 150); });
                root.addEventListener('change', update);
            },
        };
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.data('mediaUploader', mediaUploader);
        window.Alpine.data('watermarkPreview', watermarkPreview);
    });
})();
