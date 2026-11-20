{{-- Exact money helpers for the live lot previews: amounts are integer paisa (never floats), formatted like App\Support\Money::format. --}}
<script>
window.LotMoney = window.LotMoney || {
    toMinor: function (value) {
        var v = String(value || '').trim().replace(/,/g, '');
        if (!/^\d{1,13}(\.\d{1,2})?$/.test(v)) { return null; }
        var parts = v.split('.');
        return parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
    },
    format: function (minor) {
        var sign = minor < 0 ? '-' : '', abs = Math.abs(minor);
        var whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return sign + whole + '.' + String(abs % 100).padStart(2, '0');
    }
};
</script>
