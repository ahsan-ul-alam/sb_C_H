<meta name="color-scheme" content="light dark">
<script>
    // Runs before first paint so the wrong theme never flashes. Preference: light | dark | system (default).
    (function () {
        var pref = 'system';
        try { pref = localStorage.getItem('sb-theme') || 'system'; } catch (e) {}
        var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.themePref = pref;
    })();
</script>
