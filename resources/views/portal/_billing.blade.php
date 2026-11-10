@php($m = fn (int $v) => \App\Support\Money::format($v, config('finance.currency')))
@php($qtyFmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0')
<section class="pt-card" aria-labelledby="h-bill" style="margin-bottom:1.25rem">
    <div class="pt-card-head"><h2 id="h-bill">Product billing &amp; payments</h2><span class="sub">Kept apart from your approved investment: an invoice is not a cost, and a payment is not a sale.</span></div>
    <div class="pt-card-body">
        <div class="pt-grid pt-g3" style="margin:0">
            <x-portal.kpi tone="sm" icon="invoice" label="Product amount billed" :value="$m($billing['billed'])" hint="Invoices linked to your sourcing lots" />
            <x-portal.kpi tone="sm" icon="check-circle" label="Verified payments on them" :value="$m($billing['paid'])" hint="Voided payments are not counted" />
            <x-portal.kpi tone="sm" icon="alert" label="Outstanding on them" :value="$m($billing['outstanding'])" />
        </div>
        @if ($billing['mixed']['count'] > 0)
            <p class="pt-note" style="margin-top:1rem">{{ $billing['mixed']['count'] }} mixed {{ \Illuminate\Support\Str::plural('invoice', $billing['mixed']['count']) }} (products plus other items): invoice total {{ $m($billing['mixed']['invoice_total']) }}, of which products {{ $m($billing['mixed']['product_billed']) }}; {{ $m($billing['mixed']['paid']) }} paid on the whole {{ \Illuminate\Support\Str::plural('invoice', $billing['mixed']['count']) }}. A payment applies to the whole invoice, so it is not split by item.</p>
        @endif
        @if ($billing['other']['count'] > 0)
            <dl class="pt-dl" style="grid-template-columns: 1fr auto; gap:.55rem 1rem; margin-top:1.1rem">
                <dt><strong>Other invoices</strong> <span class="muted">({{ $billing['other']['count'] }}, not linked to a sourcing lot)</span></dt><dd></dd>
                <dt>Billed</dt><dd class="num" style="text-align:right">{{ $m($billing['other']['billed']) }}</dd>
                <dt>Verified payments</dt><dd class="num" style="text-align:right">{{ $m($billing['other']['paid']) }}</dd>
                <dt>Outstanding</dt><dd class="num" style="text-align:right">{{ $m($billing['other']['outstanding']) }}</dd>
            </dl>
            <p class="muted" style="font-size:.84rem;margin-top:.6rem">These cover joining fees, services and any product invoice raised before billing was linked to a lot ({{ collect($billing['other']['list'])->pluck('number')->take(6)->implode(', ') }}). They are not counted as product investment until Sellbees links them.</p>
        @endif
    </div>
    @if ($billing['awaiting_count'] > 0)
    <div class="pt-table-wrap" style="border-top:1px solid var(--border)"><table class="pt-table stack">
        <thead><tr><th>Sourced lot awaiting verification</th><th class="r">Units received</th><th class="r">Approved price value</th><th>What is still needed</th></tr></thead>
        <tbody>@foreach ($billing['awaiting'] as $l)
            <tr><td class="lead"><strong>Lot {{ $l['number'] }}</strong></td><td class="r num" data-label="Units received">{{ $qtyFmt($l['units']) }}</td><td class="r num" data-label="Approved price value">{{ $m($l['approved_value']) }}</td><td data-label="Still needed"><div>@foreach ($l['reasons'] as $why){{ $why }}@if (! $loop->last)<br>@endif @endforeach</div></td></tr>
        @endforeach</tbody>
    </table></div>
    <div class="pt-card-foot">Your approved product investment is shown once Sellbees confirms ownership, your approved price and the billing for each lot. Until then it reads "Pending verification", not zero.</div>
    @endif
</section>
