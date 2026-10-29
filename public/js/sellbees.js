/*
 * SellBees ERP — progressive enhancement (no framework, no polling).
 * - Forms submit with fetch(); the server still performs every validation/authorisation check.
 * - After a successful action only the page body (#main) and sidebar are refreshed; the top bar, bell, theme
 *   switch, toast region and confirmation dialog are never replaced, and every global listener is registered once
 *   and delegated from `document`, so refreshed content needs no re-binding.
 * Without JavaScript everything falls back to ordinary form posts and links.
 */
(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ------------------------------------------------------------------ toasts */
    var region = document.getElementById('toast-region');
    var ICONS = { success: '✓', error: '!', warning: '⚠', info: 'i' };
    var recent = {};

    function toast(type, message, options) {
        if (!message || !region) return;
        var key = type + '|' + message, now = Date.now();
        if (recent[key] && now - recent[key] < 1500) return; // one toast per action
        recent[key] = now;
        while (region.children.length >= 4) region.removeChild(region.firstChild);

        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');
        var icon = document.createElement('span');
        icon.className = 'toast-icon'; icon.setAttribute('aria-hidden', 'true'); icon.textContent = ICONS[type] || 'i';
        var text = document.createElement('span');
        text.className = 'toast-text'; text.textContent = message;
        var close = document.createElement('button');
        close.type = 'button'; close.className = 'toast-close'; close.setAttribute('aria-label', 'Dismiss notification'); close.textContent = '×';
        el.appendChild(icon); el.appendChild(text); el.appendChild(close);
        region.appendChild(el);

        function remove() {
            if (!el.parentNode) return;
            if (reducedMotion) { el.remove(); return; }
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 180);
        }
        close.addEventListener('click', remove);
        setTimeout(remove, (options && options.ttl) || (type === 'success' || type === 'info' ? 4500 : 9000));
    }

    /* ------------------------------------------------------------------ helpers */
    function csrf() { return csrfMeta ? csrfMeta.content : ''; }
    function headers() { return { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }; }
    // Always request same-origin relative paths: a redirect URL built with another scheme/host (proxy, APP_URL) must not be fetched cross-origin.
    function localUrl(url) { var u = new URL(url, location.href); return u.pathname + u.search + u.hash; }

    function setLoading(form, submitter, on) {
        form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
            if (on) { b.dataset.wasDisabled = b.disabled ? '1' : ''; b.disabled = true; }
            else { b.disabled = b.dataset.wasDisabled === '1'; delete b.dataset.wasDisabled; }
        });
        if (submitter) {
            if (!form.contains(submitter)) submitter.disabled = on;
            submitter.classList.toggle('is-loading', on);
            if (on) submitter.setAttribute('aria-busy', 'true'); else submitter.removeAttribute('aria-busy');
        }
        form.dataset.busy = on ? '1' : '';
    }

    // Fields may live outside their <form> (form="id" attribute), so look at form.elements as well as the DOM subtree.
    function fieldsOf(scope) {
        var list = Array.prototype.slice.call(scope.querySelectorAll('input, select, textarea'));
        if (scope.elements) Array.prototype.forEach.call(scope.elements, function (e) { if (list.indexOf(e) === -1) list.push(e); });
        return list;
    }
    function clearErrors(scope) {
        document.querySelectorAll('.js-error').forEach(function (n) { if (scope === document || scope.contains(n) || (scope.id && n.dataset.owner === scope.id)) n.remove(); });
        fieldsOf(scope).forEach(function (n) { if (n.getAttribute('aria-invalid')) { n.removeAttribute('aria-invalid'); n.removeAttribute('aria-describedby'); } });
    }
    // Returns the messages that could not be attached to a field.
    function showFieldErrors(scope, errors) {
        clearErrors(scope);
        var loose = [], fields = fieldsOf(scope);
        Object.keys(errors || {}).forEach(function (key) {
            var bracket = key.replace(/\.(\d+)$/, '[]').replace(/\.([^.]+)/g, '[$1]');
            var el = fields.filter(function (f) { return f.name === key || f.name === bracket; })[0];
            if (!el || el.type === 'hidden') { loose.push(errors[key][0]); return; }
            var id = 'err-' + key.replace(/[^a-z0-9]/gi, '-');
            var existing = document.getElementById(id);
            if (existing) existing.remove();
            el.setAttribute('aria-invalid', 'true');
            var msg = document.createElement('div');
            msg.className = 'error js-error'; msg.id = id; msg.dataset.owner = scope.id || ''; msg.textContent = errors[key][0];
            el.setAttribute('aria-describedby', id);
            (el.closest('.field') || el.parentNode).appendChild(msg);
        });
        var first = scope.querySelector('[aria-invalid="true"]') || fields.filter(function (f) { return f.getAttribute('aria-invalid'); })[0];
        if (first) first.focus();
        return loose;
    }

    /* ------------------------------------------------------------------ per-content enhancers (idempotent; re-run after every refresh) */
    var MONEY = /(^|\[)(amount|previous_amount|previous_withdrawals|expenses_liabilities|other_liabilities|other_receivables|other_adjustment)(\]|$)/;

    function enhance(root) {
        // ৳ prefix on finance screens only; a display aid, the field and its value are unchanged.
        if (location.pathname.indexOf('/finance') === 0) {
            root.querySelectorAll('input').forEach(function (input) {
                if (!MONEY.test(input.name || '') || input.type === 'hidden' || input.closest('.input-affix')) return;
                var wrap = document.createElement('div'); wrap.className = 'input-affix';
                var prefix = document.createElement('span'); prefix.className = 'affix'; prefix.setAttribute('aria-hidden', 'true'); prefix.textContent = '৳';
                input.parentNode.insertBefore(wrap, input); wrap.appendChild(prefix); wrap.appendChild(input);
                if (!input.getAttribute('inputmode')) input.setAttribute('inputmode', 'decimal');
                input.setAttribute('autocomplete', 'off');
            });
        }
        // Searchable <select data-searchable> helper (was inline in the layout, which only ran once).
        root.querySelectorAll('select[data-searchable]').forEach(function (select) {
            if (select.dataset.enhanced) return;
            select.dataset.enhanced = '1';
            var input = document.createElement('input');
            input.type = 'search'; input.className = 'select-search';
            input.placeholder = select.dataset.searchable || 'Type to filter…';
            input.setAttribute('aria-label', input.placeholder);
            select.parentNode.insertBefore(input, select);
            input.addEventListener('input', function () {
                var term = input.value.trim().toLowerCase(), firstMatch = null;
                Array.prototype.forEach.call(select.options, function (o) {
                    var match = !o.value || o.text.toLowerCase().indexOf(term) !== -1;
                    o.hidden = !match;
                    if (match && o.value && !firstMatch) firstMatch = o;
                });
                if (firstMatch && ((select.selectedOptions[0] && select.selectedOptions[0].hidden) || !select.value)) {
                    select.value = firstMatch.value; select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
        // Server-side validation messages from an ordinary (non-AJAX) submission.
        if (window.__errors && Object.keys(window.__errors).length) {
            var loose = showFieldErrors(document, window.__errors);
            window.__errors = {};
        }
        // Sidebar groups: restore collapsed state (sidebar markup is replaced on refresh, so listeners attach to fresh nodes).
        var closed = [];
        try { closed = JSON.parse(localStorage.getItem('sb-nav-closed') || '[]'); } catch (e) {}
        document.querySelectorAll('.nav-group').forEach(function (g) {
            if (g.dataset.bound) return;
            g.dataset.bound = '1';
            if (closed.indexOf(g.dataset.group) !== -1 && !g.querySelector('a.active')) g.removeAttribute('open');
            g.addEventListener('toggle', function () {
                var now = [];
                document.querySelectorAll('.nav-group:not([open])').forEach(function (x) { now.push(x.dataset.group); });
                try { localStorage.setItem('sb-nav-closed', JSON.stringify(now)); } catch (e) {}
            });
        });
    }

    /* ------------------------------------------------------------------ in-place refresh of #main + sidebar (links are NOT hijacked) */
    var navSeq = 0, navAbort = null, swapped = false, shownUrl = location.pathname + location.search;

    function resetDrawer() {
        document.body.classList.remove('nav-open');
        var t = document.getElementById('nav-toggle');
        if (t) t.setAttribute('aria-expanded', 'false');
    }

    // Returns true when the new content is in place.
    async function swapPage(url, opts) {
        opts = opts || {};
        url = localUrl(url);
        if (navAbort) navAbort.abort();
        navAbort = new AbortController();
        var seq = ++navSeq;
        var main = document.getElementById('main');
        if (main) { main.classList.add('is-refreshing'); main.setAttribute('aria-busy', 'true'); }
        var active = document.activeElement;
        var activeName = active && main && main.contains(active) ? active.name : '';
        var caret = active ? active.selectionStart : null;
        try {
            var res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin', signal: navAbort.signal });
            if (seq !== navSeq) return false; // a newer request superseded this one
            var final = new URL(res.url);
            if (res.status === 401 || final.pathname === '/login') { location.assign('/login'); return false; }
            if (!res.ok) { toast('error', res.status === 403 ? 'You do not have permission to view that page.' : res.status === 404 ? 'That page could not be found.' : 'Unable to load the page. Please try again.'); return false; }
            var doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            if (seq !== navSeq) return false;
            var fresh = doc.getElementById('main'), scripts = doc.getElementById('page-scripts');
            if (!fresh || (scripts && scripts.textContent.trim())) { location.assign(final.pathname + final.search); return false; } // pages with their own scripts load normally

            var oldY = window.scrollY, sameScreen = final.pathname === location.pathname;
            main.innerHTML = fresh.innerHTML;
            document.title = doc.title;
            var sidebar = document.getElementById('sidebar'), freshSide = doc.getElementById('sidebar');
            if (sidebar && freshSide) sidebar.innerHTML = freshSide.innerHTML;
            var freshToken = doc.querySelector('meta[name="csrf-token"]');
            if (freshToken && csrfMeta) csrfMeta.content = freshToken.content;
            resetDrawer();
            var target = final.pathname + final.search;
            if (opts.push !== false && target !== location.pathname + location.search) history.pushState({ sb: 1 }, '', target);
            shownUrl = target; swapped = true;
            window.__errors = {};
            enhance(document);
            window.scrollTo(0, sameScreen ? oldY : 0);

            var again = activeName ? main.querySelector('[name="' + activeName + '"]') : null;
            if (again) { again.focus(); try { again.setSelectionRange(caret, caret); } catch (e) {} } // keep typing in a live search box
            else if (!document.activeElement || document.activeElement === document.body || !document.activeElement.isConnected) main.focus({ preventScroll: true });
            loadCount();
            return true;
        } catch (e) {
            if (e.name === 'AbortError') return false;
            toast('error', 'Unable to complete the request. Please try again.');
            return false;
        } finally {
            if (seq === navSeq && main) { main.classList.remove('is-refreshing'); main.removeAttribute('aria-busy'); }
        }
    }

    // Back/forward: refresh the body for in-app history entries; ignore hash-only changes.
    window.addEventListener('popstate', function () {
        var now = location.pathname + location.search;
        if (swapped && now !== shownUrl) swapPage(now, { push: false });
    });

    /* ------------------------------------------------------------------ AJAX form submission */
    var dialog = document.getElementById('confirm-dialog');
    var sensitive = /\/(approve|post|pay|decide|verify|cancel|review|confirm-profit)(\/|$)|\/users(\/\d+)?$/;
    var pending = null;

    function needsConfirm(form) {
        if (form.dataset.confirm) return true;
        try { return sensitive.test(new URL(form.action, location.href).pathname); } catch (e) { return false; }
    }
    function isFinancial(form) {
        try { return needsConfirm(form) || new URL(form.action, location.href).pathname.indexOf('/finance') === 0; } catch (e) { return false; }
    }
    function usesAjax(form) {
        if (form.hasAttribute('data-no-ajax') || form.method.toLowerCase() === 'get') return false;
        try { var p = new URL(form.action, location.href); return p.origin === location.origin && !/\/(login|logout)$/.test(p.pathname); } catch (e) { return false; }
    }

    async function ajaxSubmit(form, submitter) {
        if (form.dataset.busy) return; // a request for this form is already in flight
        clearErrors(form);
        setLoading(form, submitter, true);
        var data;
        try { data = new FormData(form, submitter || undefined); } catch (e) { data = new FormData(form); }
        var keepLocked = false, refreshState = false;
        try {
            var res = await fetch(localUrl(form.action), { method: 'POST', body: data, headers: headers(), credentials: 'same-origin' });
            var json = null;
            try { json = await res.json(); } catch (e) {}

            if (res.ok && (!json || json.ok !== false)) {
                if (json && json.message) toast('success', json.message);
                // The action is done on the server. Keep the form locked until the refreshed page replaces it,
                // so a second click cannot repeat a financial action.
                var done = await swapPage((json && json.redirect) || location.href, { push: true });
                if (!done && form.isConnected) {
                    keepLocked = true;
                    toast('warning', 'Saved. Reload the page to see the latest data before doing anything else.', { ttl: 12000 });
                }
                return;
            }
            if (res.status === 422) {
                var loose = json && json.errors ? showFieldErrors(form, json.errors) : [];
                toast('error', json && json.errors ? (loose[0] || 'Please correct the highlighted fields.') : ((json && json.message) || 'The request could not be completed.'));
            } else if (res.status === 401) { location.assign('/login'); keepLocked = true; return; }
            else if (res.status === 403) toast('warning', (json && json.message && json.message !== 'This action is unauthorized.') ? json.message : 'You do not have permission to do that.');
            else if (res.status === 404) toast('error', 'That record could not be found. It may have been removed.');
            else if (res.status === 409) { toast('warning', (json && json.message) || 'This record changed. Review the latest state before trying again.'); refreshState = true; }
            else if (res.status === 413) toast('error', 'The file is too large to upload.');
            else if (res.status === 419) toast('warning', 'Your session expired. Reload the page, check whether the action already happened, then try again.', { ttl: 12000 });
            else toast('error', 'Something went wrong on the server. Check the record before repeating a financial action.');
        } catch (e) {
            // Outcome unknown. Never auto-retry; for financial forms show the server's current state instead of the stale page.
            toast('error', 'Unable to complete the request. Please try again.');
            refreshState = isFinancial(form);
            if (refreshState) toast('info', 'Showing the latest saved state — check it before repeating the action.', { ttl: 9000 });
        } finally {
            if (!keepLocked && form.isConnected) setLoading(form, submitter, false);
        }
        if (refreshState) swapPage(location.href, { push: false });
    }

    function run(form, submitter) {
        if (usesAjax(form)) { ajaxSubmit(form, submitter); return; }
        form.dataset.native = '1'; // ordinary submission (login, logout, data-no-ajax)
        if (form.requestSubmit) { submitter ? form.requestSubmit(submitter) : form.requestSubmit(); } else form.submit();
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.dataset.native) { // second pass of an ordinary submission: keep the duplicate-click guard
            if (form.dataset.submitted) { event.preventDefault(); return; }
            form.dataset.submitted = '1';
            var s = event.submitter;
            setTimeout(function () { form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) { b.disabled = true; }); if (s) s.classList.add('is-loading'); }, 0);
            return;
        }
        if (form.method.toLowerCase() === 'get') {
            if (form.matches('[role=search], .filters, [data-live-form]')) {
                event.preventDefault();
                var params = new URLSearchParams();
                new FormData(form).forEach(function (v, k) { if (v !== '') params.append(k, v); });
                var base = new URL(form.getAttribute('action') || location.pathname, location.href);
                swapPage(base.pathname + (params.toString() ? '?' + params : ''));
            }
            return;
        }
        event.preventDefault();
        if (form.dataset.busy) return;
        var submitter = event.submitter;
        if (needsConfirm(form) && dialog && typeof dialog.showModal === 'function') {
            if (dialog.open) return;
            var label = (submitter && submitter.textContent.replace(/\s+/g, ' ').trim()) || 'this action';
            pending = { form: form, submitter: submitter, opener: document.activeElement };
            document.getElementById('confirm-title').textContent = form.dataset.confirmTitle || label;
            document.getElementById('confirm-text').textContent = form.dataset.confirm || ('This will be recorded in the audit log under your name and may be hard to undo. Continue with “' + label + '”?');
            dialog.showModal();
            document.getElementById('confirm-cancel').focus();
            return;
        }
        run(form, submitter);
    });

    if (dialog) {
        var restoreFocus = function (p) { if (p && p.opener && p.opener.isConnected && p.opener.focus) p.opener.focus(); };
        document.getElementById('confirm-cancel').addEventListener('click', function () { var p = pending; pending = null; dialog.close(); restoreFocus(p); });
        dialog.addEventListener('cancel', function () { var p = pending; pending = null; restoreFocus(p); }); // Esc
        document.getElementById('confirm-ok').addEventListener('click', function () {
            var p = pending; pending = null; dialog.close();
            if (p) run(p.form, p.submitter);
        });
    }

    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-submitted], form[data-native]').forEach(function (form) {
            delete form.dataset.submitted; delete form.dataset.native;
            form.querySelectorAll('button').forEach(function (b) { b.disabled = false; b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); });
        });
    });

    /* ------------------------------------------------------------------ live search / filters / pagination (delegated) */
    function isLiveForm(f) { return f && f.matches && f.matches('[role=search], .filters, [data-live-form]'); }
    var debounce;
    document.addEventListener('input', function (event) {
        var el = event.target;
        if (el.type !== 'search' || el.classList.contains('select-search') || !isLiveForm(el.form)) return;
        clearTimeout(debounce);
        debounce = setTimeout(function () { el.form.requestSubmit(); }, 320);
    });
    document.addEventListener('change', function (event) {
        var el = event.target;
        if (el.form && isLiveForm(el.form) && el.matches('select, input[type=date], input[type=month], input[type=checkbox]') && !el.classList.contains('select-search')) el.form.requestSubmit();
    });
    document.addEventListener('click', function (event) {
        var t = event.target.closest ? event.target : event.target.parentElement;
        var opener = t && t.closest('a[data-read-url]');
        if (opener) { fetch(localUrl(opener.dataset.readUrl), { method: 'POST', headers: headers(), credentials: 'same-origin', keepalive: true }).catch(function () {}); }
        var a = t && t.closest('a[data-live]');
        if (!a || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || a.target === '_blank') return;
        event.preventDefault();
        swapPage(a.href);
    });

    /* ------------------------------------------------------------------ notification bell (static markup in the top bar; loads on demand; no polling) */
    var bell = document.getElementById('notif-bell');
    var panel = document.getElementById('notif-panel');
    var badge = document.getElementById('notif-badge');
    var list = document.getElementById('notif-list');
    var feedSeq = 0, countSeq = 0;

    function setBadge(n) {
        if (!badge) return;
        badge.hidden = !(n > 0);
        badge.textContent = n > 99 ? '99+' : String(n);
        bell.setAttribute('aria-label', n > 0 ? 'Notifications, ' + (n > 99 ? 'more than 99' : n) + ' unread' : 'Notifications, none unread');
    }
    async function loadCount() {
        if (!bell) return;
        var seq = ++countSeq;
        try {
            var r = await fetch(bell.dataset.countUrl, { headers: headers(), credentials: 'same-origin' });
            if (r.ok && seq === countSeq) setBadge((await r.json()).unread);
        } catch (e) { /* the bell is a convenience; a failed count never blocks work */ }
    }
    function safePath(u) { return typeof u === 'string' && u.charAt(0) === '/' && u.charAt(1) !== '/'; }

    async function markRead(id) {
        try { await fetch(bell.dataset.readUrl.replace('__ID__', encodeURIComponent(id)), { method: 'POST', headers: headers(), credentials: 'same-origin' }); } catch (e) {}
    }

    async function loadFeed() {
        var seq = ++feedSeq;
        list.setAttribute('aria-busy', 'true');
        list.innerHTML = '<li class="notif-empty muted">Loading…</li>';
        try {
            var r = await fetch(bell.dataset.feedUrl, { headers: headers(), credentials: 'same-origin' });
            if (r.status === 401) { location.assign('/login'); return; }
            if (!r.ok) throw new Error('feed');
            var data = await r.json();
            if (seq !== feedSeq) return; // a newer refresh is in flight
            setBadge(data.unread);
            list.innerHTML = '';
            if (!data.items.length) {
                var empty = document.createElement('li'); empty.className = 'notif-empty';
                var strong = document.createElement('strong'); strong.textContent = 'You\'re all caught up.';
                var sub = document.createElement('span'); sub.className = 'muted'; sub.textContent = 'New approvals and updates will appear here.';
                empty.appendChild(strong); empty.appendChild(sub); list.appendChild(empty);
            }
            data.items.forEach(function (n) {
                var li = document.createElement('li'); li.className = 'notif-item' + (n.read ? '' : ' is-unread');
                var open = document.createElement(safePath(n.url) ? 'a' : 'div'); open.className = 'notif-open';
                if (safePath(n.url)) open.href = n.url;
                var cat = document.createElement('span'); cat.className = 'notif-cat'; cat.textContent = n.category;
                var t = document.createElement('span'); t.className = 'notif-title'; t.textContent = (n.read ? '' : 'Unread: ') + n.title;
                var m = document.createElement('span'); m.className = 'notif-msg'; m.textContent = n.message;
                var tm = document.createElement('span'); tm.className = 'notif-time muted'; tm.textContent = n.time;
                open.appendChild(cat); open.appendChild(t); open.appendChild(m); open.appendChild(tm);
                if (safePath(n.url)) {
                    open.addEventListener('click', async function (e) {
                        if (e.metaKey || e.ctrlKey || e.shiftKey) return;
                        e.preventDefault(); closePanel(false);
                        if (!n.read) await markRead(n.id); // finish before the refresh so the badge count is right
                        swapPage(n.url);
                    });
                }
                li.appendChild(open); list.appendChild(li);
            });
        } catch (e) {
            if (seq === feedSeq) list.innerHTML = '<li class="notif-empty">Notifications could not be loaded. Use Refresh to try again.</li>';
        } finally { if (seq === feedSeq) list.removeAttribute('aria-busy'); }
    }
    function openPanel() { panel.hidden = false; bell.setAttribute('aria-expanded', 'true'); loadFeed(); }
    function closePanel(restoreFocus) { if (panel.hidden) return; panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); if (restoreFocus !== false) bell.focus(); }

    if (bell && panel) {
        bell.addEventListener('click', function () { panel.hidden ? openPanel() : closePanel(); });
        document.getElementById('notif-refresh').addEventListener('click', loadFeed);
        document.getElementById('notif-read-all').addEventListener('click', async function () {
            try {
                var r = await fetch(bell.dataset.readAllUrl, { method: 'POST', headers: headers(), credentials: 'same-origin' });
                if (r.ok) { setBadge(0); toast('success', 'All notifications marked as read.'); loadFeed(); }
                else if (r.status === 401) location.assign('/login');
                else toast('error', 'Unable to update notifications.');
            } catch (e) { toast('error', 'Unable to complete the request. Please try again.'); }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) closePanel(); });
        document.addEventListener('click', function (e) { if (!panel.hidden && !e.target.closest('#notif-panel') && !e.target.closest('#notif-bell')) closePanel(false); });
        panel.addEventListener('focusout', function (e) { if (!panel.hidden && e.relatedTarget && !panel.contains(e.relatedTarget) && e.relatedTarget !== bell) closePanel(false); });
    }

    /* ------------------------------------------------------------------ boot */
    window.SB = { toast: toast, refresh: function () { return swapPage(location.href, { push: false }); } };
    enhance(document);
    loadCount();
})();
