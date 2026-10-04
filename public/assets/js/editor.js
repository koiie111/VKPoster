// Post editor (CSP-safe: no inline scripts, no innerHTML, no eval). One Alpine component, `composer`, wires everything on the page:
// channel chips, the "separately for each network" switch with one tab per channel, text formatting and counters, the media list
// (library picker, upload on the fly, reordering), link buttons, the live preview, validation by the server and autosave of drafts.
// Markup contract: templates/components/composer.twig and templates/workspace/posts/editor.twig; server side: PostController.
(function () {
    'use strict';

    // ---- small helpers ------------------------------------------------------------------------------------------------

    function qs(root, sel) { return root.querySelector(sel); }
    function qsa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
    function debounce(fn, ms) {
        var timer = null;
        return function () {
            var args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fn.apply(null, args); }, ms);
        };
    }
    function csrf() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }

    // ---- the editor's markup (same grammar as App\Domain\Post\TextFormatter) --------------------------------------------

    var MARKUP = new RegExp(
        '\\\\([\\\\*_~\\[\\]()])' +
        '|\\[([^\\]\\n]+)\\]\\((https?:\\/\\/[^\\s()]+(?:\\([^\\s()]*\\)[^\\s()]*)*)\\)' +
        '|\\*\\*(?=\\S)((?:(?!\\*\\*(?!\\*)).)+?)(?<=\\S)\\*\\*(?!\\*)' +
        '|~~(?=\\S)((?:(?!~~).)+?)(?<=\\S)~~' +
        '|\\*(?=[^\\s*])([^*\\n]+?)(?<=[^\\s*])\\*' +
        '|(?<![\\p{L}\\p{N}_])_(?=[^\\s_])([^_\\n]+?)(?<=[^\\s_])_(?![\\p{L}\\p{N}_])',
        'gu'
    );

    /** Parse markup into nodes: {t: 'text'|'b'|'i'|'s'|'a', v: string, c: [nodes]}. */
    function parse(text) {
        var nodes = [];
        var last = 0;
        var m;
        // A fresh regex per call: parse() recurses and the global flag keeps state in lastIndex.
        var re = new RegExp(MARKUP.source, MARKUP.flags);
        while ((m = re.exec(text)) !== null) {
            if (m.index > last) { nodes.push({ t: 'text', v: text.slice(last, m.index), c: [] }); }
            last = m.index + m[0].length;
            if (m[1] !== undefined) { nodes.push({ t: 'text', v: m[1], c: [] }); }
            else if (m[2] !== undefined && m[3] !== undefined) { nodes.push({ t: 'a', v: m[3], c: parse(m[2]) }); }
            else if (m[4] !== undefined) { nodes.push({ t: 'b', v: '', c: parse(m[4]) }); }
            else if (m[5] !== undefined) { nodes.push({ t: 's', v: '', c: parse(m[5]) }); }
            else if (m[6] !== undefined) { nodes.push({ t: 'i', v: '', c: parse(m[6]) }); }
            else if (m[7] !== undefined) { nodes.push({ t: 'i', v: '', c: parse(m[7]) }); }
            if (re.lastIndex === m.index) { re.lastIndex += 1; }
        }
        if (last < text.length) { nodes.push({ t: 'text', v: text.slice(last), c: [] }); }
        return nodes;
    }

    function visibleOf(nodes) {
        return nodes.map(function (n) { return n.t === 'text' ? n.v : visibleOf(n.c); }).join('');
    }

    /** Text as the reader sees it (links shown as their text): what counters measure. */
    function visibleLength(text) { return visibleOf(parse(text)).length; }

    /** Render markup into `target`: rich elements for networks with HTML, plain text (links as "text (url)") for the others. */
    function render(target, text, rich) {
        clear(target);
        var walk = function (nodes, parent) {
            nodes.forEach(function (n) {
                if (n.t === 'text') { parent.appendChild(document.createTextNode(n.v)); return; }
                if (n.t === 'a') {
                    if (rich) {
                        var a = el('a', 'text-primary-text underline');
                        a.href = n.v;
                        a.rel = 'noopener noreferrer';
                        a.target = '_blank';
                        walk(n.c, a);
                        parent.appendChild(a);
                    } else {
                        var label = visibleOf(n.c);
                        parent.appendChild(document.createTextNode(label === n.v ? n.v : label + ' (' + n.v + ')'));
                    }
                    return;
                }
                if (!rich) { walk(n.c, parent); return; }
                var node = el(n.t === 'b' ? 'strong' : (n.t === 'i' ? 'em' : 's'));
                walk(n.c, node);
                parent.appendChild(node);
            });
        };
        walk(parse(text), target);
    }

    /** Wrap the selection of a textarea in markers (or insert a link). */
    function format(field, kind) {
        var start = field.selectionStart;
        var end = field.selectionEnd;
        var value = field.value;
        var picked = value.slice(start, end);
        var before = '';
        var after = '';
        var inner = picked;
        if (kind === 'bold') { before = '**'; after = '**'; if (!picked) { inner = 'жирный'; } }
        if (kind === 'italic') { before = '_'; after = '_'; if (!picked) { inner = 'курсив'; } }
        if (kind === 'strike') { before = '~~'; after = '~~'; if (!picked) { inner = 'зачёркнутый'; } }
        if (kind === 'link') {
            var url = window.prompt('Адрес ссылки (начинается с https://)', 'https://');
            if (!url || !/^https?:\/\/\S+$/i.test(url.trim())) { field.focus(); return; }
            before = '[';
            after = '](' + url.trim() + ')';
            if (!picked) { inner = 'текст ссылки'; }
        }
        field.value = value.slice(0, start) + before + inner + after + value.slice(end);
        field.focus();
        field.setSelectionRange(start + before.length, start + before.length + inner.length);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // ---- the component ------------------------------------------------------------------------------------------------

    function composer() {
        return {
            init: function () {
                var root = this.$el;
                var form = qs(root, '[data-composer-form]');
                if (!form) { return; }

                var channelInputs = qsa(form, 'input[data-channel]');
                var perNetwork = qs(form, '#per-network');
                var tabsBox = qs(form, '[data-tabs]');
                var pvTabs = qs(root, '[data-pv-tabs]');
                var pvPanel = qs(root, '[data-pv-panel]');
                var pvEmpty = qs(root, '[data-pv-empty]');
                var problemsBox = qs(form, '[data-problems]');
                var channelsError = qs(form, '[data-channels-error]');
                var autosaveStatus = qs(form, '[data-autosave-status]');
                var picker = document.getElementById('media-picker');
                var uploadInput = document.getElementById('editor-upload');
                var buttonTemplate = qs(root, 'template[data-button-template]');
                var validateUrl = root.getAttribute('data-validate-url');
                var autosaveUrl = root.getAttribute('data-autosave-url');
                var pickerUrl = root.getAttribute('data-picker-url');
                var uploadUrl = root.getAttribute('data-upload-url');
                var updateBase = root.getAttribute('data-update-base');
                var postId = root.getAttribute('data-post-id') || '';
                var canPublish = root.getAttribute('data-can-publish') === '1';
                var MAX_BUTTONS = 3;

                var panels = {};
                qsa(form, '[data-panel]').forEach(function (p) { panels[p.getAttribute('data-panel')] = p; });
                var current = 'common';
                var previewKey = null;
                var submitting = false;

                function caps(input) {
                    try { return JSON.parse(input.getAttribute('data-caps') || '{}'); } catch (e) { return {}; }
                }
                function selected() { return channelInputs.filter(function (i) { return i.checked; }); }
                function selectedIds() { return selected().map(function (i) { return i.value; }); }
                function channelById(id) { return channelInputs.filter(function (i) { return i.value === id; })[0] || null; }
                function isCustom(panel, what) {
                    var flag = qs(panel, '[data-custom="' + what + '"]');
                    return !!flag && flag.value === '1';
                }
                function setCustom(panel, what, on) {
                    var flag = qs(panel, '[data-custom="' + what + '"]');
                    if (flag) { flag.value = on ? '1' : '0'; }
                    refreshInheritNote(panel);
                }
                function refreshInheritNote(panel) {
                    var any = isCustom(panel, 'text') || isCustom(panel, 'media') || isCustom(panel, 'options');
                    var note = qs(panel, '[data-inherit-note]');
                    var reset = qs(panel, '[data-reset-variant]');
                    if (note) { note.textContent = any ? 'Для этой соцсети есть свои изменения.' : 'Пока всё совпадает с общим постом. Изменили что-то здесь, и эта соцсеть получит свой вариант.'; }
                    if (reset) { reset.hidden = !any; }
                }

                // ---- media model per panel -------------------------------------------------------------------------------
                var models = {};
                Object.keys(panels).forEach(function (key) {
                    var list = [];
                    qsa(panels[key], '[data-media-source] li').forEach(function (li) {
                        list.push({ id: li.getAttribute('data-media-id'), name: li.getAttribute('data-name'), kind: li.getAttribute('data-kind'), thumb: li.getAttribute('data-thumb') || '', missing: li.getAttribute('data-missing') === '1' });
                    });
                    models[key] = list;
                });

                function mediaLimit() {
                    var chosen = selected();
                    if (!chosen.length) { return 10; }
                    return chosen.reduce(function (m, i) { return Math.min(m, caps(i).max_media || 10); }, 10);
                }

                function fieldName(panel, suffix) {
                    var prefix = panel.getAttribute('data-prefix');
                    return prefix ? prefix + '[' + suffix + ']' : suffix;
                }

                function renderMedia(key) {
                    var panel = panels[key];
                    var list = qs(panel, '[data-media-list]');
                    var note = qs(panel, '[data-media-note]');
                    clear(list);
                    var model = models[key];
                    model.forEach(function (item, index) {
                        var row = el('li', 'flex items-center gap-3 rounded-ctl border border-line bg-surface p-2');
                        row.draggable = true;
                        row.setAttribute('data-index', String(index));
                        var grip = el('span', 'shrink-0 cursor-grab text-muted');
                        grip.setAttribute('aria-hidden', 'true');
                        grip.textContent = '⋮⋮';
                        row.appendChild(grip);
                        var thumb;
                        if (item.thumb) {
                            thumb = el('img', 'h-12 w-12 shrink-0 rounded-sm object-cover');
                            thumb.src = item.thumb;
                            thumb.alt = '';
                        } else {
                            thumb = el('span', 'flex h-12 w-12 shrink-0 items-center justify-center rounded-sm bg-primary-soft text-xs font-semibold text-primary-softfg', item.kind === 'video' ? 'Видео' : 'Файл');
                        }
                        row.appendChild(thumb);
                        var name = el('div', 'min-w-0 flex-1');
                        name.appendChild(el('p', 'truncate text-sm font-medium', item.name));
                        if (item.missing) { name.appendChild(el('p', 'text-xs font-medium text-bad-fg', 'Файла больше нет в медиатеке. Уберите его из поста.')); }
                        else if (index === 0) { name.appendChild(el('p', 'text-xs text-muted', 'Обложка')); }
                        row.appendChild(name);
                        var hidden = el('input');
                        hidden.type = 'hidden';
                        hidden.name = fieldName(panel, 'media') + '[]';
                        hidden.value = item.id;
                        row.appendChild(hidden);
                        var mk = function (label, text, handler, disabled) {
                            var b = el('button', 'btn btn-ghost !min-h-9 !px-2 w-9', text);
                            b.type = 'button';
                            b.setAttribute('aria-label', label);
                            b.title = label;
                            b.disabled = !!disabled;
                            b.addEventListener('click', handler);
                            return b;
                        };
                        row.appendChild(mk('Поднять выше', '↑', function () { moveMedia(key, index, index - 1); }, index === 0));
                        row.appendChild(mk('Опустить ниже', '↓', function () { moveMedia(key, index, index + 1); }, index === model.length - 1));
                        row.appendChild(mk('Убрать из поста', '✕', function () { model.splice(index, 1); changedMedia(key); }, false));
                        row.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', String(index)); e.dataTransfer.effectAllowed = 'move'; row.classList.add('opacity-50'); });
                        row.addEventListener('dragend', function () { row.classList.remove('opacity-50'); });
                        row.addEventListener('dragover', function (e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
                        row.addEventListener('drop', function (e) {
                            e.preventDefault();
                            var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
                            if (!isNaN(from)) { moveMedia(key, from, index); }
                        });
                        list.appendChild(row);
                    });
                    var limit = mediaLimit();
                    note.textContent = model.length ? 'Файлов: ' + model.length + ' из ' + limit : '';
                    note.className = 'text-sm ' + (model.length > limit ? 'font-medium text-bad-fg' : 'text-muted');
                }

                function moveMedia(key, from, to) {
                    var model = models[key];
                    if (to < 0 || to >= model.length || from === to) { return; }
                    model.splice(to, 0, model.splice(from, 1)[0]);
                    changedMedia(key);
                }

                /** The media of a panel changed: a variant becomes its own, the common list flows into the variants that follow it. */
                function changedMedia(key) {
                    if (key !== 'common') { setCustom(panels[key], 'media', true); }
                    syncVariants();
                    renderMedia(key);
                    refresh();
                }

                function addMedia(key, items) {
                    var model = models[key];
                    items.forEach(function (item) {
                        if (!model.some(function (m) { return m.id === item.id; })) { model.push({ id: item.id, name: item.name, kind: item.kind, thumb: item.thumb || '', missing: false }); }
                    });
                    changedMedia(key);
                }

                // ---- inheritance: variants follow the common post until changed ---------------------------------------------
                function copyOptions(from, to) {
                    qsa(from, '[data-opt]').forEach(function (src) {
                        var dst = qs(to, '[data-opt="' + src.getAttribute('data-opt') + '"]');
                        if (!dst) { return; }
                        if (src.type === 'checkbox') { dst.checked = src.checked; } else { dst.value = src.value; }
                    });
                    var rows = qs(to, '[data-buttons]');
                    clear(rows);
                    qsa(from, '[data-button-row]').forEach(function (row) {
                        addButtonRow(to, qs(row, '[data-button-text]').value, qs(row, '[data-button-url]').value);
                    });
                }

                function syncVariants() {
                    var common = panels.common;
                    Object.keys(panels).forEach(function (key) {
                        if (key === 'common') { return; }
                        var panel = panels[key];
                        if (!isCustom(panel, 'text')) { qs(panel, '[data-text]').value = qs(common, '[data-text]').value; }
                        if (!isCustom(panel, 'media')) { models[key] = models.common.map(function (m) { return Object.assign({}, m); }); renderMedia(key); }
                        if (!isCustom(panel, 'options')) { copyOptions(common, panel); }
                        refreshInheritNote(panel);
                    });
                }

                // ---- link buttons ------------------------------------------------------------------------------------------
                function renumberButtons(panel) {
                    var prefix = panel.getAttribute('data-prefix');
                    qsa(panel, '[data-button-row]').forEach(function (row, i) {
                        var base = prefix ? prefix + '[buttons][' + i + ']' : 'buttons[' + i + ']';
                        var uid = (prefix ? prefix.replace(/[\[\]]/g, '-') : '') + '-btn-' + i + '-' + panel.getAttribute('data-panel');
                        var text = qs(row, '[data-button-text]');
                        var url = qs(row, '[data-button-url]');
                        text.name = base + '[text]';
                        url.name = base + '[url]';
                        text.id = uid + '-text';
                        url.id = uid + '-url';
                        var labels = qsa(row, 'label');
                        if (labels[0]) { labels[0].htmlFor = text.id; }
                        if (labels[1]) { labels[1].htmlFor = url.id; }
                    });
                    var add = qs(panel, '[data-button-add]');
                    if (add) { add.hidden = qsa(panel, '[data-button-row]').length >= MAX_BUTTONS; }
                }

                function addButtonRow(panel, text, url) {
                    if (!buttonTemplate) { return; }
                    var holder = qs(panel, '[data-buttons]');
                    var row = buttonTemplate.content.firstElementChild.cloneNode(true);
                    qs(row, '[data-button-text]').value = text || '';
                    qs(row, '[data-button-url]').value = url || '';
                    holder.appendChild(row);
                    renumberButtons(panel);
                }

                // ---- text, counters, preview -----------------------------------------------------------------------------
                function effectiveText(key) {
                    var panel = panels[key];
                    return qs(panel, '[data-text]').value;
                }

                function textLimit(key) {
                    if (key === 'common') {
                        var chosen = selected();
                        return chosen.length ? chosen.reduce(function (m, i) { return Math.min(m, caps(i).max_text || 4096); }, Infinity) : null;
                    }
                    var input = channelById(key);
                    return input ? (caps(input).max_text || 4096) : null;
                }

                function updateCounters() {
                    Object.keys(panels).forEach(function (key) {
                        var out = qs(panels[key], '[data-counter]');
                        if (!out) { return; }
                        var n = visibleLength(effectiveText(key));
                        var limit = textLimit(key);
                        out.textContent = limit === null ? n + ' симв.' : n + ' из ' + limit;
                        var over = limit !== null && n > limit;
                        out.classList.toggle('text-bad-fg', over);
                        out.classList.toggle('font-semibold', over);
                    });
                }

                function updateOptionVisibility() {
                    Object.keys(panels).forEach(function (key) {
                        var panel = panels[key];
                        var sources = key === 'common' ? selected() : [channelById(key)].filter(Boolean);
                        var shown = 0;
                        qsa(panel, '[data-cap]').forEach(function (block) {
                            var cap = block.getAttribute('data-cap');
                            var ok = sources.some(function (i) { return !!caps(i)[cap]; });
                            block.hidden = !ok;
                            if (ok) { shown += 1; }
                        });
                        var none = qs(panel, '[data-no-options]');
                        if (none) { none.hidden = shown > 0 || sources.length === 0; }
                    });
                }

                function previewData(key) {
                    var panel = panels[key] || panels.common;
                    var customMedia = key !== 'common' && isCustom(panel, 'media');
                    var media = (key === 'common' || !customMedia) ? models.common : models[key];
                    var texts = qs(panel, '[data-text]').value;
                    var buttons = qsa(panel, '[data-button-row]').map(function (row) {
                        return { text: qs(row, '[data-button-text]').value.trim(), url: qs(row, '[data-button-url]').value.trim() };
                    }).filter(function (b) { return b.text; });
                    return { text: texts, media: key === 'common' ? models.common : (customMedia ? models[key] : models.common), buttons: buttons, mediaList: media };
                }

                function initials(name) {
                    var parts = name.replace(/[«»"]/g, '').trim().split(/\s+/).filter(function (w) { return /[\p{L}\d]/u.test(w); });
                    return ((parts[0] || '').slice(0, 1) + (parts[1] ? parts[1].slice(0, 1) : '')).toUpperCase();
                }

                function buildPreview(input) {
                    var key = input.value;
                    var kindMap = { tg: 'tg', vk: 'vk', max: 'max', ig: 'ig', fake: 'tg' };
                    var tpl = qs(root, 'template[data-pv-template="' + (kindMap[input.getAttribute('data-badge')] || 'tg') + '"]');
                    if (!tpl) { return null; }
                    var node = tpl.content.firstElementChild.cloneNode(true);
                    var data = previewData(key);
                    var c = caps(input);
                    var name = qs(node, '[data-pv-name]');
                    if (name) { name.textContent = input.getAttribute('data-name'); }
                    var ini = qs(node, '[data-pv-initials]');
                    if (ini) { ini.textContent = initials(input.getAttribute('data-name')); }
                    var text = qs(node, '[data-pv-text]');
                    if (text) {
                        if (data.text.trim()) { render(text, data.text, c.format === 'html'); text.classList.remove('text-muted'); }
                        else { text.textContent = 'Здесь появится ваш текст'; text.classList.add('text-muted'); }
                    }
                    var media = qs(node, '[data-pv-media]');
                    if (media) {
                        var shown = data.mediaList.slice(0, 4);
                        media.className = media.className.replace(/grid-cols-\d/g, '');
                        media.classList.add(shown.length > 1 ? 'grid-cols-2' : 'grid-cols-1');
                        shown.forEach(function (m, i) {
                            var cell = el('div', 'relative aspect-video overflow-hidden bg-primary-soft text-primary-softfg');
                            if (m.thumb) {
                                var img = el('img', 'h-full w-full object-cover');
                                img.src = m.thumb;
                                img.alt = '';
                                cell.appendChild(img);
                            } else {
                                cell.appendChild(el('span', 'flex h-full items-center justify-center text-xs font-semibold', m.kind === 'video' ? 'Видео' : 'Файл'));
                            }
                            if (i === 3 && data.mediaList.length > 4) { cell.appendChild(el('span', 'absolute inset-0 flex items-center justify-center bg-black/50 text-lg font-semibold text-white', '+' + (data.mediaList.length - 3))); }
                            media.appendChild(cell);
                        });
                    }
                    var buttons = qs(node, '[data-pv-buttons]');
                    if (buttons && c.buttons) {
                        data.buttons.slice(0, MAX_BUTTONS).forEach(function (b) {
                            buttons.appendChild(el('span', 'block rounded-ctl bg-primary-soft px-3 py-1.5 text-center text-sm font-semibold text-primary-softfg', b.text));
                        });
                    }
                    return node;
                }

                function refreshPreview() {
                    var chosen = selected();
                    clear(pvTabs);
                    clear(pvPanel);
                    pvEmpty.hidden = chosen.length > 0;
                    pvTabs.hidden = chosen.length < 2;
                    if (!chosen.length) { return; }
                    if (!previewKey || !chosen.some(function (i) { return i.value === previewKey; })) { previewKey = chosen[0].value; }
                    chosen.forEach(function (input) {
                        var tab = el('button', 'tab', input.getAttribute('data-name'));
                        tab.type = 'button';
                        tab.setAttribute('role', 'tab');
                        tab.setAttribute('aria-selected', String(input.value === previewKey));
                        tab.tabIndex = input.value === previewKey ? 0 : -1;
                        tab.addEventListener('click', function () { previewKey = input.value; refreshPreview(); });
                        pvTabs.appendChild(tab);
                    });
                    var input = channelById(previewKey);
                    var node = input ? buildPreview(input) : null;
                    if (node) { pvPanel.appendChild(node); }
                }

                // ---- tabs of the editor ----------------------------------------------------------------------------------
                function refreshTabs() {
                    var chosen = selected();
                    var separate = !!perNetwork && perNetwork.checked && chosen.length > 0;
                    if (!separate || (current !== 'common' && !chosen.some(function (i) { return i.value === current; }))) { current = 'common'; }
                    clear(tabsBox);
                    tabsBox.hidden = !separate;
                    var entries = [{ key: 'common', label: 'Общий пост' }].concat(chosen.map(function (i) { return { key: i.value, label: i.getAttribute('data-name') }; }));
                    if (separate) {
                        entries.forEach(function (entry) {
                            var tab = el('button', 'tab', entry.label);
                            tab.type = 'button';
                            tab.id = 'tab-' + entry.key;
                            tab.setAttribute('role', 'tab');
                            tab.setAttribute('aria-selected', String(entry.key === current));
                            tab.setAttribute('aria-controls', 'panel-' + entry.key);
                            tab.tabIndex = entry.key === current ? 0 : -1;
                            if (entry.key !== 'common' && (isCustom(panels[entry.key], 'text') || isCustom(panels[entry.key], 'media') || isCustom(panels[entry.key], 'options'))) {
                                tab.appendChild(el('span', 'ml-1 text-xs font-medium text-primary-text', '· свой'));
                            }
                            tab.addEventListener('click', function () { current = entry.key; if (entry.key !== 'common') { previewKey = entry.key; } refresh(); });
                            tab.addEventListener('keydown', function (e) {
                                var i = entries.indexOf(entry);
                                var target = null;
                                if (e.key === 'ArrowRight') { target = entries[(i + 1) % entries.length]; }
                                if (e.key === 'ArrowLeft') { target = entries[(i - 1 + entries.length) % entries.length]; }
                                if (target) { e.preventDefault(); current = target.key; refresh(); var n = document.getElementById('tab-' + target.key); if (n) { n.focus(); } }
                            });
                            tabsBox.appendChild(tab);
                        });
                    }
                    Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== current; });
                }

                // ---- validation by the server ------------------------------------------------------------------------------
                var lastProblems = null;
                function showProblems(data) {
                    clear(problemsBox);
                    var map = (data && data.problems) || {};
                    var any = false;
                    channelInputs.forEach(function (input) {
                        var list = map[input.value];
                        if (!input.checked || !list || !list.length) { return; }
                        any = true;
                        var box = el('div', 'flex gap-2 rounded-ctl bg-bad-soft px-3 py-2 text-sm text-bad-fg');
                        box.setAttribute('role', 'alert');
                        var body = el('div');
                        body.appendChild(el('strong', '', input.getAttribute('data-name') + ': '));
                        body.appendChild(document.createTextNode(list.join(' ')));
                        box.appendChild(body);
                        problemsBox.appendChild(box);
                    });
                    lastProblems = any ? map : null;
                }

                var validate = debounce(function () {
                    if (!validateUrl || !selected().length || submitting) { showProblems(null); return; }
                    fetch(validateUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
                        body: new URLSearchParams(new FormData(form)).toString(),
                    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) { if (data) { showProblems(data); } }).catch(function () { /* the server checks again on submit */ });
                }, 700);

                // ---- autosave of drafts ------------------------------------------------------------------------------------
                var dirty = false;
                var saving = false;
                var autosave = debounce(function () {
                    if (!autosaveUrl || submitting || saving || !dirty) { return; }
                    saving = true;
                    dirty = false;
                    var body = new URLSearchParams(new FormData(form));
                    body.delete('intent');
                    if (postId) { body.set('post', postId); }
                    if (autosaveStatus) { autosaveStatus.textContent = 'Сохраняем черновик…'; }
                    fetch(autosaveUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
                        body: body.toString(),
                    }).then(function (r) { return r.json().catch(function () { return {}; }); }).then(function (data) {
                        if (data && data.ok && data.id) {
                            postId = data.id;
                            root.setAttribute('data-post-id', postId);
                            form.setAttribute('action', data.update_url);
                            try { window.history.replaceState(null, '', data.edit_url); } catch (e) { /* ignore */ }
                            if (autosaveStatus) { autosaveStatus.textContent = 'Черновик сохранён в ' + data.saved_at; }
                        } else if (data && data.skipped) {
                            if (autosaveStatus) { autosaveStatus.textContent = ''; }
                        } else if (autosaveStatus) {
                            autosaveStatus.textContent = 'Автосохранение не сработало: ' + ((data && data.message) || 'нет связи') + '. Сохраните пост кнопкой ниже.';
                        }
                    }).catch(function () {
                        if (autosaveStatus) { autosaveStatus.textContent = 'Нет связи, черновик пока не сохранён. Сохраните пост кнопкой ниже.'; }
                        dirty = true;
                    }).then(function () { saving = false; if (dirty) { autosave(); } });
                }, 4000);

                // ---- refresh everything that depends on the state --------------------------------------------------------
                function refresh() {
                    refreshTabs();
                    updateCounters();
                    updateOptionVisibility();
                    refreshPreview();
                    var chosen = selected();
                    Object.keys(panels).forEach(function (key) { if (!panels[key].hidden) { renderMedia(key); } });
                    if (channelsError && chosen.length) { channelsError.hidden = true; }
                }

                function changed() {
                    dirty = true;
                    validate();
                    autosave();
                }

                // ---- wiring ------------------------------------------------------------------------------------------------
                channelInputs.forEach(function (input) {
                    input.addEventListener('change', function () { refresh(); changed(); });
                });
                if (perNetwork) { perNetwork.addEventListener('change', function () { refresh(); changed(); }); }

                Object.keys(panels).forEach(function (key) {
                    var panel = panels[key];
                    var text = qs(panel, '[data-text]');
                    text.addEventListener('input', function () {
                        if (key !== 'common') { setCustom(panel, 'text', true); }
                        syncVariants();
                        updateCounters();
                        refreshPreview();
                        refreshTabsLabelsOnly();
                        changed();
                    });
                    text.addEventListener('keydown', function (e) {
                        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey) {
                            if (e.key === 'b' || e.key === 'и') { e.preventDefault(); format(text, 'bold'); }
                            if (e.key === 'i' || e.key === 'ш') { e.preventDefault(); format(text, 'italic'); }
                        }
                    });
                    qsa(panel, '[data-fmt]').forEach(function (b) { b.addEventListener('click', function () { format(text, b.getAttribute('data-fmt')); }); });

                    // Options: a change in a variant makes its options its own; a change in the common ones flows on.
                    panel.addEventListener('input', function (e) {
                        if (!e.target.closest || !e.target.closest('[data-options]')) { return; }
                        if (key !== 'common') { setCustom(panel, 'options', true); } else { syncVariants(); }
                        refreshPreview();
                        refreshTabsLabelsOnly();
                        changed();
                    });
                    panel.addEventListener('change', function (e) {
                        if (!e.target.closest || !e.target.closest('[data-options]')) { return; }
                        if (key !== 'common') { setCustom(panel, 'options', true); } else { syncVariants(); }
                        refreshPreview();
                        changed();
                    });
                    var addButton = qs(panel, '[data-button-add]');
                    if (addButton) {
                        addButton.addEventListener('click', function () {
                            if (qsa(panel, '[data-button-row]').length >= MAX_BUTTONS) { return; }
                            addButtonRow(panel, '', '');
                            if (key !== 'common') { setCustom(panel, 'options', true); } else { syncVariants(); }
                            var rows = qsa(panel, '[data-button-row]');
                            var field = qs(rows[rows.length - 1], '[data-button-text]');
                            if (field) { field.focus(); }
                            changed();
                        });
                    }
                    panel.addEventListener('click', function (e) {
                        var remove = e.target.closest ? e.target.closest('[data-button-remove]') : null;
                        if (!remove) { return; }
                        var row = remove.closest('[data-button-row]');
                        if (row) { row.parentNode.removeChild(row); renumberButtons(panel); if (key !== 'common') { setCustom(panel, 'options', true); } else { syncVariants(); } refreshPreview(); changed(); }
                    });
                    var reset = qs(panel, '[data-reset-variant]');
                    if (reset) {
                        reset.addEventListener('click', function () {
                            ['text', 'media', 'options'].forEach(function (what) { setCustom(panel, what, false); });
                            syncVariants();
                            refresh();
                            changed();
                        });
                    }
                    renumberButtons(panel);

                    // Media: pick from the library, upload, and drop files on the block.
                    var pick = qs(panel, '[data-media-pick]');
                    if (pick && picker) { pick.addEventListener('click', function () { openPicker(key); }); }
                    var upload = qs(panel, '[data-media-upload]');
                    if (upload && uploadInput) { upload.addEventListener('click', function () { uploadTarget = key; uploadInput.click(); }); }
                    var block = qs(panel, '[data-media-block]');
                    if (block) {
                        block.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0) { e.preventDefault(); } });
                        block.addEventListener('drop', function (e) {
                            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); uploadFiles(key, e.dataTransfer.files); }
                        });
                    }
                    renderMedia(key);
                });

                function refreshTabsLabelsOnly() { refreshTabs(); }

                // ---- upload on the fly -----------------------------------------------------------------------------------
                var uploadTarget = 'common';
                function uploadFiles(key, files) {
                    var status = qs(panels[key], '[data-upload-status]');
                    Array.prototype.forEach.call(files, function (file) {
                        var line = el('li', 'text-sm text-muted', file.name + ': загружаем…');
                        status.appendChild(line);
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', uploadUrl);
                        xhr.setRequestHeader('Accept', 'application/json');
                        xhr.setRequestHeader('X-CSRF-Token', csrf());
                        xhr.upload.addEventListener('progress', function (e) {
                            if (e.lengthComputable) { line.textContent = file.name + ': ' + Math.round(e.loaded * 100 / e.total) + ' %'; }
                        });
                        var fail = function (message) {
                            line.textContent = file.name + ': ' + message;
                            line.className = 'text-sm font-medium text-bad-fg';
                            line.setAttribute('role', 'alert');
                        };
                        xhr.addEventListener('load', function () {
                            var data = null;
                            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                            if (xhr.status === 419) { fail('страница устарела, обновите её и попробуйте ещё раз.'); return; }
                            if (xhr.status === 429) { fail('слишком много загрузок подряд, подождите минуту.'); return; }
                            if (xhr.status === 413) { fail('файл слишком большой.'); return; }
                            if (data && data.ok && data.items && data.items[0]) {
                                addMedia(key, [data.items[0]]);
                                line.textContent = file.name + ': готово, файл сохранён и в медиатеке.';
                                line.className = 'text-sm text-ok-fg';
                                window.setTimeout(function () { if (line.parentNode) { line.parentNode.removeChild(line); } }, 5000);
                            } else if (data && data.errors && data.errors.length) {
                                fail(String(data.errors[0]).replace(/^[^:]*:\s*/, ''));
                            } else {
                                fail('не удалось загрузить. Попробуйте ещё раз.');
                            }
                        });
                        xhr.addEventListener('error', function () { fail('нет связи с сервером.'); });
                        var data = new FormData();
                        data.append('file', file);
                        xhr.send(data);
                    });
                }
                if (uploadInput) {
                    uploadInput.addEventListener('change', function () {
                        if (uploadInput.files.length) { uploadFiles(uploadTarget, uploadInput.files); }
                        uploadInput.value = '';
                    });
                }

                // ---- library picker ------------------------------------------------------------------------------------------
                var pickerKey = 'common';
                var pickerPage = 1;
                var chosenInPicker = [];
                function openPicker(key) {
                    pickerKey = key;
                    chosenInPicker = [];
                    pickerPage = 1;
                    loadPicker(true);
                    picker.showModal();
                    var s = qs(picker, '[data-picker-search]');
                    if (s) { s.focus(); }
                }

                function loadPicker(reset) {
                    var grid = qs(picker, '[data-picker-grid]');
                    var status = qs(picker, '[data-picker-status]');
                    var more = qs(picker, '[data-picker-more]');
                    if (reset) { clear(grid); pickerPage = 1; }
                    status.textContent = 'Загружаем…';
                    var params = new URLSearchParams({ page: String(pickerPage), q: qs(picker, '[data-picker-search]').value, kind: qs(picker, '[data-picker-kind]').value });
                    fetch(pickerUrl + '?' + params.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                        .then(function (data) {
                            data.items.forEach(function (item) {
                                var li = el('li');
                                var label = el('label', 'block cursor-pointer');
                                var box = el('input', 'peer sr-only');
                                box.type = 'checkbox';
                                box.value = item.id;
                                box.checked = chosenInPicker.some(function (c) { return c.id === item.id; });
                                box.addEventListener('change', function () {
                                    if (box.checked) { chosenInPicker.push(item); } else { chosenInPicker = chosenInPicker.filter(function (c) { return c.id !== item.id; }); }
                                });
                                var tile = el('span', 'block overflow-hidden rounded-ctl border-2 border-line bg-surface peer-checked:border-primary peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-primary-text');
                                var thumb;
                                if (item.thumb) { thumb = el('img', 'aspect-square w-full object-cover'); thumb.src = item.thumb; thumb.alt = ''; }
                                else { thumb = el('span', 'flex aspect-square w-full items-center justify-center bg-primary-soft text-xs font-semibold text-primary-softfg', item.kind_label); }
                                tile.appendChild(thumb);
                                tile.appendChild(el('span', 'block truncate px-2 py-1 text-xs', item.name));
                                label.appendChild(box);
                                label.appendChild(tile);
                                li.appendChild(label);
                                grid.appendChild(li);
                            });
                            status.textContent = grid.children.length ? '' : 'Ничего не нашли. Загрузите файл в медиатеку или в редакторе.';
                            more.hidden = data.page >= data.pages;
                        })
                        .catch(function () { status.textContent = 'Не удалось загрузить медиатеку. Попробуйте ещё раз.'; });
                }
                if (picker) {
                    qs(picker, '[data-picker-search]').addEventListener('input', debounce(function () { loadPicker(true); }, 300));
                    qs(picker, '[data-picker-kind]').addEventListener('change', function () { loadPicker(true); });
                    qs(picker, '[data-picker-more]').addEventListener('click', function () { pickerPage += 1; loadPicker(false); });
                    qs(picker, '[data-picker-add]').addEventListener('click', function () {
                        if (chosenInPicker.length) { addMedia(pickerKey, chosenInPicker); }
                        picker.close();
                    });
                }

                // ---- submit ---------------------------------------------------------------------------------------------------
                form.addEventListener('submit', function (e) {
                    var submitter = e.submitter;
                    var intent = submitter ? submitter.value : 'draft';
                    if (submitter && submitter.hasAttribute('formaction')) { return; } // "save as template" has its own checks on the server
                    if ((intent === 'schedule' || intent === 'now') && !selected().length) {
                        e.preventDefault();
                        if (channelsError) { channelsError.textContent = 'Выберите хотя бы один канал, куда опубликовать пост.'; channelsError.hidden = false; }
                        if (channelInputs[0]) { channelInputs[0].focus(); }
                        return;
                    }
                    if (intent === 'now' && !window.confirm('Опубликовать пост сейчас во всех выбранных каналах?')) { e.preventDefault(); return; }
                    if (lastProblems && (intent === 'schedule' || intent === 'now')) {
                        // The server will refuse and show the same text; do not make the person wait for it.
                        e.preventDefault();
                        var first = problemsBox.firstElementChild;
                        if (first && first.scrollIntoView) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                        return;
                    }
                    submitting = true;
                });
                window.addEventListener('beforeunload', function (e) {
                    if (dirty && autosaveUrl && !submitting) { e.preventDefault(); e.returnValue = ''; }
                });

                syncVariants();
                // `syncVariants` copies the common values into variants that are not custom: show them in the state the server rendered.
                refresh();
                if (selected().length) { validate(); }
                dirty = false;
            },
        };
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.data('composer', composer);
    });
})();
