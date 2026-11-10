@props(['label', 'value', 'hint' => null, 'icon' => null, 'tone' => null, 'pending' => false])
<div @class(['pt-kpi', 'is-primary' => $tone === 'primary', 'is-sm' => $tone === 'sm', 'is-pending' => $pending])>
    <div class="pt-kpi-top">@if ($icon)<span class="pt-kpi-ico"><x-icon :name="$icon" /></span>@endif<span>{{ $label }}</span></div>
    <div class="pt-kpi-value">{{ $value }}</div>
    @if ($hint)<div class="pt-kpi-hint">{{ $hint }}</div>@endif
</div>
