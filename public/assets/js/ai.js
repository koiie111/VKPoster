// AI assistant in the post editor (CSP-safe: no inline scripts, no HTML injection, no eval). Sends the current text to
// POST /w/{id}/ai/run and shows the answer as plain text (textContent only); the person decides whether it goes into the post.
// Markup contract: the [data-ai] section of templates/workspace/posts/editor.twig; server side: App\Http\Controllers\Ai\AiController.
(function () {
    'use strict';

    var HINTS = {
        generate: 'Опишите тему: ассистент напишет черновик. Если в посте уже есть текст, он тоже будет учтён.',
        rewrite: 'Перепишем текст поста под выбранную соцсеть: длину, тон и эмодзи. Пожелания можно дописать здесь.',
        shorten: 'Сократим текст поста до лимита выбранной соцсети, сохранив смысл.',
        titles: 'Предложим 5 вариантов заголовка к тексту поста.',
        hashtags: 'Подберём хэштеги к тексту поста.',
        spellcheck: 'Исправим орфографию и пунктуацию, не меняя смысл и стиль.'
    };
    var LABELS = { generate: 'Написать', rewrite: 'Переписать', shorten: 'Сократить', titles: 'Придумать', hashtags: 'Подобрать', spellcheck: 'Проверить' };

    function csrf() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }

    function activeTextarea(root) {
        var all = Array.prototype.slice.call(root.ownerDocument.querySelectorAll('textarea[data-text]'));
        for (var i = 0; i < all.length; i++) {
            if (all[i].offsetParent !== null) { return all[i]; }
        }
        return all[0] || null;
    }

    /** Platform, length limit and id of the channel being written for: the one whose tab is open, else the first selected (caps are in data-caps). */
    function target(textarea) {
        var chip = null;
        var m = textarea && /^pf-(.+)-text$/.exec(textarea.id || '');
        if (m && m[1] !== 'common') {
            chip = document.querySelector('input[data-channel][value="' + m[1] + '"]');
        }
        chip = chip || document.querySelector('input[data-channel]:checked');
        if (!chip) { return { platform: '', limit: 0, channel: '' }; }
        var limit = 0;
        try { limit = parseInt((JSON.parse(chip.getAttribute('data-caps') || '{}') || {}).maxText, 10) || 0; } catch (e) { limit = 0; }
        return { platform: chip.getAttribute('data-platform') || '', limit: limit, channel: chip.value || '' };
    }

    function setText(textarea, value) {
        textarea.value = value;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
    }

    function init(root) {
        var select = root.querySelector('[data-ai-action]');
        var brief = root.querySelector('[data-ai-brief]');
        var hint = root.querySelector('[data-ai-hint]');
        var run = root.querySelector('[data-ai-run]');
        var runLabel = root.querySelector('[data-ai-run-label]');
        var status = root.querySelector('[data-ai-status]');
        var error = root.querySelector('[data-ai-error]');
        var result = root.querySelector('[data-ai-result]');
        var out = root.querySelector('[data-ai-text]');
        var over = root.querySelector('[data-ai-over]');
        var url = root.getAttribute('data-ai-url');
        var action = function () { return select.value; };

        function sync() {
            hint.textContent = HINTS[action()] || '';
            runLabel.textContent = LABELS[action()] || 'Сделать';
        }
        function showError(message, link) {
            error.hidden = false;
            error.textContent = message;
            if (link) {
                error.appendChild(document.createTextNode(' '));
                var a = document.createElement('a');
                a.href = link;
                a.className = 'underline';
                a.textContent = 'Перейти на тариф';
                error.appendChild(a);
            }
        }
        function reset() { error.hidden = true; error.textContent = ''; status.textContent = ''; }

        select.addEventListener('change', sync);
        sync();

        run.addEventListener('click', function () {
            if (run.disabled) { return; }
            var textarea = activeTextarea(root);
            reset();
            var t = target(textarea);
            var body = new URLSearchParams();
            body.set('action', action());
            body.set('text', textarea ? textarea.value : '');
            body.set('brief', brief.value);
            body.set('platform', t.platform);
            body.set('limit', String(t.limit));
            body.set('channel', t.channel);
            run.disabled = true;
            run.setAttribute('aria-busy', 'true');
            status.textContent = 'Ассистент думает…';
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
                body: body.toString()
            }).then(function (response) {
                return response.json().catch(function () { return { ok: false, message: 'Не получилось получить ответ. Попробуйте ещё раз.' }; });
            }).then(function (data) {
                if (!data.ok) {
                    result.hidden = true;
                    status.textContent = '';
                    showError(data.message || 'Не получилось получить ответ.', data.upgrade);
                    return;
                }
                out.textContent = data.text;
                over.hidden = !data.over_limit;
                result.hidden = false;
                var parts = [];
                if (data.style === 'channel') { parts.push('стиль канала «' + (data.style_channel || '') + '»'); } else if (data.style === 'workspace') { parts.push('голос бренда'); }
                if (data.credits > 0) { parts.push('списано кредитов: ' + data.credits); }
                if (data.credits_left !== null && data.credits_left !== undefined) { parts.push('осталось: ' + data.credits_left); }
                status.textContent = parts.join(', ');
            }).catch(function () {
                status.textContent = '';
                showError('Нет связи с сервером. Текст поста не потерян: попробуйте ещё раз.');
            }).then(function () {
                run.disabled = false;
                run.removeAttribute('aria-busy');
            });
        });

        root.querySelector('[data-ai-replace]').addEventListener('click', function () {
            var textarea = activeTextarea(root);
            if (textarea) { setText(textarea, out.textContent || ''); }
        });
        root.querySelector('[data-ai-append]').addEventListener('click', function () {
            var textarea = activeTextarea(root);
            if (textarea) { setText(textarea, (textarea.value ? textarea.value.replace(/\s+$/, '') + '\n\n' : '') + (out.textContent || '')); }
        });
        root.querySelector('[data-ai-discard]').addEventListener('click', function () {
            result.hidden = true;
            out.textContent = '';
            reset();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.slice.call(document.querySelectorAll('[data-ai]')).forEach(init);
    });
})();
