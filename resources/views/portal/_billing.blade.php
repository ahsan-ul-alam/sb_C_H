@php
    $m = fn (int $v) => \App\Support\Money::format($v, config('finance.currency'));
    $qtyFmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $t = $fin['totals'];
@endphp
<section class="pt-card" aria-labelledby="h-bill" style="margin-bottom:1.25rem">
    <div class="pt-card-head"><h2 id="h-bill">My financial overview</h2><a class="pt-link" href="{{ route('portal.finance') }}">Category details</a></div>
    <div class="pt-card-body">
        <div class="pt-grid pt-g3" style="margin:0">
            <x-portal.kpi tone="sm" icon="invoice" label="Total billed" :value="$m($t['invoiced'])" :hint="$t['invoices'].' '.\Illuminate\Support\Str::plural('invoice', $t['invoices'])" />
            <x-portal.kpi tone="sm" icon="check-circle" label="Total paid" :value="$m($t['paid'])" hint="Verified payments only" />
            <x-portal.kpi tone="sm" icon="alert" label="Total due" :value="$m($t['due'])" />
        </div>
    </div>
    @if (count($fin['by_category']) || $fin['mixed']['count'])
    <div class="pt-table-wrap" style="border-top:1px solid var(--border)"><table class="pt-table stack">
        <thead><tr><th>Category</th><th class="r">Billed</th><th class="r">Paid</th><th class="r">Due</th></tr></thead>
        <tbody>
        @foreach ($fin['by_category'] as $key => $c)<tr><td class="lead"><a class="pt-link" href="{{ route('portal.finance', ['category' => $key]) }}#invoices">{{ $c['label'] }}</a></td><td class="r num" data-label="Billed">{{ $m($c['billed']) }}</td><td class="r num" data-label="Paid">{{ $m($c['paid']) }}</td><td class="r num" data-label="Due">{{ $m($c['due']) }}</td></tr>@endforeach
        @if ($fin['mixed']['count'])<tr><td class="lead"><a class="pt-link" href="{{ route('portal.finance', ['category' => 'mixed']) }}#invoices">Invoices with several categories</a><small>Paid at invoice level: category allocation pending</small></td><td class="r num" data-label="Billed">{{ $m($fin['mixed']['billed']) }}</td><td class="r num" data-label="Paid">{{ $m($fin['mixed']['paid']) }}</td><td class="r num" data-label="Due">{{ $m($fin['mixed']['due']) }}</td></tr>@endif
        </tbody>
    </table></div>
    @endif
    @if ($billing['awaiting_count'] > 0)
    <div class="pt-table-wrap" style="border-top:1px solid var(--border)"><table class="pt-table stack">
        <thead><tr><th>Sourced lot awaiting verification</th><th class="r">Units received</th><th class="r">Approved price value</th><th>What is still needed</th></tr></thead>
        <tbody>@foreach ($billing['awaiting'] as $l)
            <tr><td class="lead"><strong>Lot {{ $l['number'] }}</strong></td><td class="r num" data-label="Units received">{{ $qtyFmt($l['units']) }}</td><td class="r num" data-label="Approved price value">{{ $m($l['approved_value']) }}</td><td data-label="Still needed"><div>@foreach ($l['reasons'] as $why){{ $why }}@if (! $loop->last)<br>@endif @endforeach</div></td></tr>
        @endforeach</tbody>
    </table></div>
    <div class="pt-card-foot">Your approved product investment is shown once Sellbees confirms ownership, your approved price and the billing for each lot. Until then it reads "Pending verification", not zero. Your received stock is still listed.</div>
    @endif
</section>