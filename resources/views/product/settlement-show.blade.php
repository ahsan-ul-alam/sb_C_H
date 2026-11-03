@use('App\Support\Money')
@use('Illuminate\Support\Str')
<x-layout :title="'Settlement — '.$client->name">
    <x-page-header :title="$client->name" :crumbs="[['Client Settlements', route('product.settlements')], [$client->name, null]]">
        <x-slot:meta>Recording a settlement records that the payment was made; it does not send any money.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>Statement</h2></div>
        <div class="table-wrap"><table class="table">
            <tr><td>Successful (delivered, not returned) sales</td><td class="num">{{ Money::format($s['delivered_sales']) }}</td></tr>
            <tr><td>Customer money collected</td><td class="num">{{ Money::format($s['collected']) }}</td></tr>
            <tr><td>Already settled to date (fees netted + paid)</td><td class="num">− {{ Money::format($s['settled']) }}</td></tr>
            <tr><td class="strong">Client money held now</td><td class="num strong">{{ Money::format($s['funds_held']) }}</td></tr>
            <tr><td>Commission owed (unpaid)</td><td class="num">− {{ Money::format($s['commission_due']) }}</td></tr>
            <tr><td>Storage fees owed (unpaid){{ $s['storage_due'] === 0 && $s['storage_not_deductible'] > 0 ? ' — not deductible: '.Money::format($s['storage_not_deductible']).' stays a client invoice' : '' }}</td><td class="num">− {{ Money::format($s['storage_due']) }}</td></tr>
            <tr><td class="strong">Deducted now (only what the money held can cover)</td><td class="num strong">− {{ Money::format($s['netting']) }}</td></tr>
            <tr><td class="strong">Net payable to the client</td><td class="num strong">{{ Money::format($s['payable']) }}</td></tr>
        </table></div>
        @can('settlement.record')
        @if ($s['funds_held'] > 0)
        <form method="POST" action="{{ route('product.settlements.store', $client) }}" class="panel-body">@csrf
            <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
            <div class="form-grid">
                <div class="field"><label>Pay to the client now (blank = all {{ Money::format($s['payable']) }}; type less for a part payment)</label><input name="pay" inputmode="decimal" placeholder="{{ Money::toDecimal($s['payable']) }}"></div>
                <div class="field"><label>Date</label><input type="date" name="paid_on" value="{{ today()->toDateString() }}" required></div>
                <div class="field"><label>Transfer reference</label><input name="reference"></div>
                @if ($accounts->isNotEmpty())<div class="field"><label>Paid from</label><select name="financial_account_id"><option value="">Default account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>@endif
            </div>
            <button class="btn btn-primary" style="margin-top:.8rem">Record client settlement</button>
            <p class="muted">Fees shown above are netted from the money held first; then the amount entered is recorded as paid. Any unpaid balance stays held for the client.</p>
        </form>
        @endif
        @endcan
    </section>

    <section class="panel"><div class="panel-head"><h2>Orders</h2></div><div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Order</th><th>Delivery</th><th class="num">Delivered value</th><th class="num">Commission</th><th class="num">Collected</th></tr></thead>
        <tbody>
        @forelse ($orders as $o)
            <tr><td>{{ $o->reference }}</td><td>{{ ucfirst($o->delivery_status) }}</td><td class="num">{{ Money::format($o->delivery_status === 'delivered' ? $o->deliveredValue() : 0) }}</td><td class="num">{{ Money::format($o->commission) }}</td><td class="num">{{ Money::format((int) $o->collections->sum('amount')) }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">No orders.</td></tr>
        @endforelse
        </tbody>
    </table></div></section>

    <section class="panel"><div class="panel-head"><h2>Settlement history</h2></div><div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Date</th><th class="num">Money held before</th><th class="num">Commission netted</th><th class="num">Storage netted</th><th class="num">Paid to client</th><th>Reference</th></tr></thead>
        <tbody>
        @forelse ($s['history'] as $h)
            <tr><td>{{ $h->paid_on->format('j M Y') }}</td><td class="num">{{ Money::format($h->funds_before) }}</td><td class="num">{{ Money::format($h->commission_netted) }}</td><td class="num">{{ Money::format($h->storage_netted) }}</td><td class="num strong">{{ Money::format($h->paid) }}</td><td>{{ $h->reference }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted">No settlements recorded.</td></tr>
        @endforelse
        </tbody>
    </table></div></section>
</x-layout>
