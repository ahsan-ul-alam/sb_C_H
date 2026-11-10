/* Sellbees Client Portal: drawer, loading feedback, resend countdown and digit-only code entry. No data is calculated here. */
(function () {
    'use strict';
    var side = document.getElementById('pt-side');
    var overlay = document.getElementById('pt-overlay');
    var toggle = document.getElementById('pt-menu');
    var bar = document.getElementById('pt-loadbar');

    function setDrawer(open) {
        if (!side) { return; }
        side.classList.toggle('is-open', open);
        if (overlay) { overlay.classList.toggle('is-open', open); }
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) { var first = side.querySelector('a'); if (first) { first.focus(); } } else if (toggle) { toggle.focus({ preventScroll: true }); }
    }
    if (toggle) { toggle.addEventListener('click', function () { setDrawer(!side.classList.contains('is-open')); }); }
    if (overlay) { overlay.addEventListener('click', function () { setDrawer(false); }); }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && side && side.classList.contains('is-open')) { setDrawer(false); } });
    window.addEventListener('resize', function () { if (window.innerWidth > 960) { setDrawer(false); } });

    function busy() { if (bar) { bar.classList.add('on'); } }
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (a && !a.target && !a.hasAttribute('download') && a.origin === location.origin && !e.metaKey && !e.ctrlKey && a.getAttribute('href').charAt(0) !== '#' && a.pathname.indexOf('/pdf') === -1) { busy(); }
    });
    document.querySelectorAll('form[data-loading]').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button[type=submit]');
            if (b && !b.disabled) { setTimeout(function () { b.classList.add('is-loading'); b.setAttribute('aria-busy', 'true'); }, 0); }
            busy();
        });
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { if (bar) { bar.classList.remove('on'); } document.querySelectorAll('.is-loading').forEach(function (b) { b.classList.remove('is-loading'); }); } });

    document.querySelectorAll('input[data-digits]').forEach(function (inp) {
        inp.addEventListener('input', function () { inp.value = inp.value.replace(/\D+/g, '').slice(0, parseInt(inp.getAttribute('maxlength') || '6', 10)); });
    });

    var cd = document.querySelector('[data-cooldown]');
    if (cd) {
        var left = parseInt(cd.getAttribute('data-cooldown'), 10) || 0;
        var label = cd.querySelector('[data-count]');
        var tick = function () {
            if (left <= 0) { cd.disabled = false; cd.removeAttribute('aria-disabled'); if (label) { label.textContent = ''; } return; }
            cd.disabled = true; cd.setAttribute('aria-disabled', 'true'); if (label) { label.textContent = ' in ' + left + 's'; }
            left -= 1; setTimeout(tick, 1000);
        };
        tick();
    }
})();
