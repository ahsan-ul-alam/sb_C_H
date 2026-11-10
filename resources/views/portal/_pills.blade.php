@php($labels = ['available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'returned' => 'Returned', 'damaged' => 'Damaged'])
<div class="pt-pills" aria-label="Quantities by status">
    @php($any = false)
    @foreach ($labels as $k => $label)
        @if (bccomp((string) ($b[$k] ?? '0'), '0', 2) > 0)
            @php($any = true)
            <span class="pt-pill"><i class="pt-dot s-{{ $k }}"></i>{{ $label }} <b>{{ rtrim(rtrim((string) $b[$k], '0'), '.') }}</b></span>
        @endif
    @endforeach
    @unless ($any)<span class="pt-pill muted">No stock movement yet</span>@endunless
</div>
