@props(['value'])
@php
    $v = strtolower((string) $value);
    $class = match (true) {
        in_array($v, ['verified', 'approved', 'posted', 'paid', 'active', 'completed', 'closed_ok', 'settled', 'utilised'], true) => 'badge-paid',
        in_array($v, ['rejected', 'cancelled', 'unpaid', 'exited', 'reversed', 'voided'], true) => 'badge-unpaid',
        default => 'badge-partial',
    };
@endphp
<span class="badge {{ $class }}">{{ ucfirst(str_replace('_', ' ', $value)) }}</span>
