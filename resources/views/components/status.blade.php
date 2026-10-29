@props(['value'])
@php
    $v = strtolower((string) $value);
    $class = match (true) {
        in_array($v, ['verified', 'approved', 'posted', 'paid', 'active', 'completed', 'closed_ok'], true) => 'badge-paid',
        in_array($v, ['rejected', 'cancelled', 'unpaid', 'exited'], true) => 'badge-unpaid',
        default => 'badge-partial',
    };
@endphp
<span class="badge {{ $class }}">{{ ucfirst($value) }}</span>
