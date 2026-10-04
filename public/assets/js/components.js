// Behavior of design-system components. Registered as Alpine.data() so markup only says x-data="name":
// CSP-safe (no inline expressions, no eval). Each component wires its own DOM in init().
// Contract per component: docs/design/design-system.md.
(function () {
    'use strict';

    function qsa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

    /** Dropdown menu: button[data-dropdown-trigger] + [data-dropdown-menu]; arrows, Esc, outside click. */
    function dropdown() {
        return {
            init: function () {
                var root = this.$el;
                var trigger = root.querySelector('[data-dropdown-trigger]');
                var menu = root.querySelector('[data-dropdown-menu]');
                if (!trigger || !menu) { return; }
                var items = function () { return qsa(menu, '[role=menuitem]'); };
                var close = function (focus) {
                    menu.hidden = true;
                    trigger.setAttribute('aria-expanded', 'false');
                    if (focus) { trigger.focus(); }
                };
                var open = function () {
                    menu.hidden = false;
                    trigger.setAttribute('aria-expanded', 'true');
                    var first = items()[0];
                    if (first) { first.focus(); }
                };
                trigger.addEventListener('click', function () { menu.hidden ? open() : close(false); });
                root.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && !menu.hidden) { e.preventDefault(); close(true); return; }
                    if (menu.hidden && e.target === trigger && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); open(); return; }
                    if (menu.hidden) { return; }
                    var list = items();
                    var i = list.indexOf(document.activeElement);
                    if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
                    if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
                    if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
                    if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
                    if (e.key === 'Tab') { close(false); }
                });
                document.addEventListener('click', function (e) { if (!root.contains(e.target)) { close(false); } });
                menu.addEventListener('click', function (e) { if (e.target.closest('[role=menuitem]')) { close(false); } });
            },
        };
    }

    /** Tabs: [role=tab][aria-controls] inside [role=tablist]; panels are [role=tabpanel]. Arrow keys move focus. */
    function tabs() {
        return {
            init: function () {
                var root = this.$el;
                var tabsList = qsa(root, '[role=tab]');
                var select = function (tab, focus) {
                    tabsList.forEach(function (t) {
                        var on = t === tab;
                        t.setAttribute('aria-selected', String(on));
                        t.tabIndex = on ? 0 : -1;
                        var panel = document.getElementById(t.getAttribute('aria-controls'));
                        if (panel) { panel.hidden = !on; }
                    });
                    if (focus) { tab.focus(); }
                };
                tabsList.forEach(function (t, i) {
                    t.addEventListener('click', function () { select(t, false); });
                    t.addEventListener('keydown', function (e) {
                        var n = null;
                        if (e.key === 'ArrowRight') { n = tabsList[(i + 1) % tabsList.length]; }
                        if (e.key === 'ArrowLeft') { n = tabsList[(i - 1 + tabsList.length) % tabsList.length]; }
                        if (e.key === 'Home') { n = tabsList[0]; }
                        if (e.key === 'End') { n = tabsList[tabsList.length - 1]; }
                        if (n) { e.preventDefault(); select(n, true); }
                    });
                });
            },
        };
    }

    /** Textarea character counter: data-counter-max on the textarea, [data-counter-out] shows "n из max". */
    function counter() {
        return {
            init: function () {
                var field = this.$el.querySelector('textarea, input');
                var out = this.$el.querySelector('[data-counter-out]');
                if (!field || !out) { return; }
                var max = parseInt(field.getAttribute('data-counter-max') || '0', 10);
                var update = function () {
                    var n = field.value.length;
                    out.textContent = max ? n + ' из ' + max : String(n);
                    out.classList.toggle('text-bad-fg', max > 0 && n > max);
                    out.classList.toggle('font-semibold', max > 0 && n > max);
                };
                field.addEventListener('input', update);
                update();
            },
        };
    }

    /** File dropzone over a native input[type=file]: drag state, list of chosen files. */
    function dropzone() {
        return {
            init: function () {
                var root = this.$el;
                var input = root.querySelector('input[type=file]');
                var list = root.querySelector('[data-dropzone-list]');
                if (!input) { return; }
                var show = function () {
                    if (!list) { return; }
                    list.textContent = Array.prototype.map.call(input.files, function (f) { return f.name; }).join(', ');
                };
                input.addEventListener('change', show);
                ['dragenter', 'dragover'].forEach(function (ev) {
                    root.addEventListener(ev, function (e) { e.preventDefault(); root.setAttribute('data-over', 'true'); });
                });
                ['dragleave', 'drop'].forEach(function (ev) {
                    root.addEventListener(ev, function () { root.removeAttribute('data-over'); });
                });
                root.addEventListener('drop', function (e) {
                    e.preventDefault();
                    if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); }
                });
            },
        };
    }

    /** Light/dark/system theme: [data-theme-toggle] flips, [data-theme-set=light|dark|system] picks. Saved in localStorage. */
    function themeSwitch() {
        var root = document.documentElement;
        var effective = function () {
            return root.getAttribute('data-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        };
        var save = function (value) {
            if (value === 'system') { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', value); }
            try { if (value === 'system') { localStorage.removeItem('theme'); } else { localStorage.setItem('theme', value); } } catch (e) { /* ignore */ }
        };
        return {
            init: function () {
                var el = this.$el;
                var sync = function () {
                    var chosen = root.getAttribute('data-theme') || 'system';
                    qsa(el, '[data-theme-set]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-theme-set') === chosen)); });
                    qsa(el, '[data-theme-toggle]').forEach(function (b) { b.setAttribute('aria-pressed', String(effective() === 'dark')); });
                };
                el.addEventListener('click', function (e) {
                    var set = e.target.closest('[data-theme-set]');
                    var tog = e.target.closest('[data-theme-toggle]');
                    if (set) { save(set.getAttribute('data-theme-set')); }
                    if (tog) { save(effective() === 'dark' ? 'light' : 'dark'); }
                    sync();
                });
                sync();
            },
        };
    }

    /** Native <dialog> opener: any [data-dialog-open=id] opens it, [data-dialog-close] / backdrop click / Esc closes it. */
    function dialogs() {
        return {
            init: function () {
                document.addEventListener('click', function (e) {
                    var opener = e.target.closest('[data-dialog-open]');
                    if (opener) {
                        var d = document.getElementById(opener.getAttribute('data-dialog-open'));
                        if (d && d.showModal) { d.showModal(); }
                        return;
                    }
                    var closer = e.target.closest('[data-dialog-close]');
                    if (closer) { var own = closer.closest('dialog'); if (own) { own.close(); } return; }
                    if (e.target.tagName === 'DIALOG' && e.target.hasAttribute('data-dialog-light-dismiss')) { e.target.close(); }
                });
            },
        };
    }

    /** Confirm dialog: submit stays disabled until the typed text equals the object name (data-confirm-name). */
    function confirmName() {
        return {
            init: function () {
                var root = this.$el;
                var input = root.querySelector('[data-confirm-input]');
                var submit = root.querySelector('[data-confirm-submit]');
                if (!input || !submit) { return; }
                var want = input.getAttribute('data-confirm-name');
                var sync = function () { submit.disabled = input.value.trim() !== want; };
                input.addEventListener('input', sync);
                sync();
            },
        };
    }

    /** Toasts: window.toast(text, kind) or an htmx response header HX-Trigger: {"toast": {"text": "...", "kind": "success"}}. */
    function toasts() {
        return {
            init: function () {
                var region = this.$el;
                var tpl = region.querySelector('template');
                var show = function (text, kind) {
                    if (!tpl) { return; }
                    var node = tpl.content.firstElementChild.cloneNode(true);
                    var k = ['success', 'error', 'info', 'warning'].indexOf(kind) >= 0 ? kind : 'info';
                    qsa(node, '[data-toast-kind]').forEach(function (n) { n.hidden = n.getAttribute('data-toast-kind') !== k; });
                    node.querySelector('[data-toast-text]').textContent = text;
                    var remove = function () { node.remove(); };
                    node.querySelector('[data-toast-close]').addEventListener('click', remove);
                    region.appendChild(node);
                    if (k !== 'error') { setTimeout(remove, 6000); }
                };
                window.toast = show;
                document.body.addEventListener('toast', function (e) { show(String((e.detail && e.detail.text) || ''), String((e.detail && e.detail.kind) || 'info')); });
                document.addEventListener('click', function (e) {
                    var t = e.target.closest('[data-toast-trigger]');
                    if (t) { show(t.getAttribute('data-toast-trigger'), t.getAttribute('data-toast-kind') || 'info'); }
                });
                qsa(document, '[data-toast-now]').forEach(function (n) { show(n.getAttribute('data-toast-now'), n.getAttribute('data-toast-kind') || 'info'); });
            },
        };
    }

    /** Post editor prototype: platform toggles, strictest character limit, live preview per platform. */
    function postEditor() {
        return {
            init: function () {
                var root = this.$el;
                var text = root.querySelector('[data-editor-text]');
                var checks = qsa(root, '[data-editor-platform]');
                var counterOut = root.querySelector('[data-editor-counter]');
                var previews = qsa(root, '[data-preview]');
                var tabsEl = qsa(root, '[data-preview-tab]');
                var empty = root.querySelector('[data-editor-empty]');
                var update = function () {
                    var active = checks.filter(function (c) { return c.checked; });
                    var limit = active.reduce(function (m, c) { return Math.min(m, parseInt(c.getAttribute('data-limit'), 10)); }, Infinity);
                    var n = text.value.length;
                    if (counterOut) {
                        counterOut.textContent = limit === Infinity ? n + ' симв.' : n + ' из ' + limit;
                        counterOut.classList.toggle('text-bad-fg', n > limit);
                        counterOut.classList.toggle('font-semibold', n > limit);
                    }
                    qsa(root, '[data-preview-text]').forEach(function (p) { p.textContent = text.value || 'Здесь появится ваш текст'; });
                    var names = active.map(function (c) { return c.getAttribute('data-editor-platform'); });
                    tabsEl.forEach(function (t) { t.hidden = names.indexOf(t.getAttribute('data-preview-tab')) < 0; });
                    var shown = tabsEl.filter(function (t) { return !t.hidden; });
                    var current = shown.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || shown[0];
                    tabsEl.forEach(function (t) {
                        var on = t === current;
                        t.setAttribute('aria-selected', String(on));
                        t.tabIndex = on ? 0 : -1;
                    });
                    previews.forEach(function (p) { p.hidden = !current || p.getAttribute('data-preview') !== current.getAttribute('data-preview-tab'); });
                    if (empty) { empty.hidden = shown.length > 0; }
                };
                tabsEl.forEach(function (t) {
                    t.addEventListener('click', function () {
                        tabsEl.forEach(function (o) { o.setAttribute('aria-selected', String(o === t)); });
                        update();
                    });
                });
                text.addEventListener('input', update);
                checks.forEach(function (c) { c.addEventListener('change', update); });
                update();
            },
        };
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.data('dropdown', dropdown);
        window.Alpine.data('tabs', tabs);
        window.Alpine.data('counter', counter);
        window.Alpine.data('dropzone', dropzone);
        window.Alpine.data('themeSwitch', themeSwitch);
        window.Alpine.data('dialogs', dialogs);
        window.Alpine.data('confirmName', confirmName);
        window.Alpine.data('toasts', toasts);
        window.Alpine.data('postEditor', postEditor);
    });
})();
