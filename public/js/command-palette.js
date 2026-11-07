/*
 * SellBees ERP: global command palette (Ctrl+K / Cmd+K). No framework. Results are built with textContent only (never innerHTML), the request is debounced
 * and cancellable, the server filters everything by the user's permissions, and the listeners are delegated from `document` so they survive page refreshes.
 */
(function () {
    'use strict';

    var dlg = document.getElementById('cmdk');
    if (!dlg || typeof dlg.showModal !== 'function') return;
    var input = document.getElementById('cmdk-input');
    var list = document.getElementById('cmdk-list');
    var status = document.getElementById('cmdk-status');
    var url = dlg.dataset.url;
    var opener = null, timer = null, controller = null, items = [], active = -1;
    var RECENT_KEY = 'sb-recent-search';

    var isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');
    document.querySelectorAll('[data-cmdk-hint]').forEach(function (el) { el.textContent = isMac ? '⌘ K' : 'Ctrl K'; });

    function recent() { try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch (e) { return []; } }
    function remember(item) {
        try {
            var next = [item].concat(recent().filter(function (r) { return r.url !== item.url; })).slice(0, 5);
            localStorage.setItem(RECENT_KEY, JSON.stringify(next));
        } catch (e) { /* storage may be blocked: recents are optional */ }
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    function setActive(index) {
        if (!items.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
        active = (index + items.length) % items.length;
        items.forEach(function (a, i) {
            var on = i === active;
            a.classList.toggle('is-active', on);
            a.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) { input.setAttribute('aria-activedescendant', a.id); a.scrollIntoView({ block: 'nearest' }); }
        });
    }

    function render(groups, query) {
        list.textContent = '';
        items = [];
        var n = 0;
        groups.forEach(function (g) {
            if (!g.items || !g.items.length) return;
            var group = el('div', 'cmdk-group');
            group.setAttribute('role', 'group');
            var label = el('div', 'cmdk-label', g.label);
            label.id = 'cmdk-group-' + n;
            group.setAttribute('aria-labelledby', label.id);
            group.appendChild(label);
            g.items.forEach(function (it) {
                var a = el('a', 'cmdk-item');
                a.href = it.url;
                a.id = 'cmdk-opt-' + (n++);
                a.setAttribute('role', 'option');
                a.setAttribute('aria-selected', 'false');
                a.appendChild(el('span', 'cmdk-t', it.title));
                if (it.subtitle) a.appendChild(el('span', 'cmdk-s', it.subtitle));
                a.addEventListener('click', function () { remember({ title: it.title, subtitle: it.subtitle || '', url: it.url }); });
                a.addEventListener('mousemove', function () { setActive(items.indexOf(a)); });
                items.push(a);
                group.appendChild(a);
            });
            list.appendChild(group);
        });
        if (!items.length) {
            list.appendChild(el('div', 'cmdk-empty', query ? 'No results for “' + query + '”. Try a name, an SB number, a phone number, an invoice number or a lot number.' : 'Start typing to search.'));
        }
        status.textContent = items.length ? items.length + (items.length === 1 ? ' result' : ' results') : 'No results';
        setActive(0);
    }

    function load(query) {
        if (controller) controller.abort();
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        fetch(url + '?q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: controller ? controller.signal : undefined })
            .then(function (r) {
                if (r.status === 401 || r.redirected) throw new Error('session');
                if (!r.ok) throw new Error('search ' + r.status);
                return r.json();
            })
            .then(function (data) {
                var groups = data.groups || [];
                if (!query) {
                    var r = recent();
                    if (r.length) groups = [{ label: 'Recent', items: r }].concat(groups);
                }
                render(groups, query);
            })
            .catch(function (e) {
                if (e && e.name === 'AbortError') return;
                list.textContent = '';
                items = [];
                list.appendChild(el('div', 'cmdk-empty', (e && e.message === 'session' ? 'Your session has expired. Reload the page and sign in again.' : 'Search is unavailable right now (' + (e && e.message ? e.message : 'network error') + '). Use the sidebar, or try again in a moment.')));
                status.textContent = 'Search unavailable';
            });
    }

    function open() {
        if (dlg.open) return;
        opener = document.activeElement;
        document.body.classList.remove('nav-open');
        dlg.showModal();
        input.value = '';
        load('');
        input.focus();
    }
    function close() { if (dlg.open) dlg.close(); }

    dlg.addEventListener('close', function () {
        if (controller) controller.abort();
        clearTimeout(timer);
        if (opener && document.contains(opener) && typeof opener.focus === 'function') opener.focus();
        opener = null;
    });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });   // a click on the backdrop

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        timer = setTimeout(function () { load(q); }, 180);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Home' && !input.value) { e.preventDefault(); setActive(0); }
        else if (e.key === 'Enter') {
            if (active >= 0 && items[active]) { e.preventDefault(); items[active].click(); }
        }
    });

    document.addEventListener('keydown', function (e) {
        // Ctrl+K or Cmd+K from anywhere in the ERP, including inside a text field; no other single key is captured.
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            dlg.open ? close() : open();
        }
    });
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-cmdk-open]') : null;
        if (trigger) { e.preventDefault(); open(); }
    });
})();
