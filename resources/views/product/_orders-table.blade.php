@use('App\Support\Money')
@use('Illuminate\Support\Str')
{{-- The order list with its fulfilment actions; shared by Orders & Delivery and the project page. Needs $orders (paginator) and $accounts. --}}
<div class="table-wrap"><table class="table table-min-760">
    <thead><tr><th>Order</th><th>Products</th><th class="num">Order value</th><th>Delivery</th><th>Commission</th><th>Collection</th><th>Actions</th></tr></thead>
    <tbody>
    @forelse ($orders as $o)
        @php($cs = \App\Services\OrderFulfilment::collectionState($o))
        <tr>
            <td class="primary-cell">{{ $o->reference }}<span class="secondary">{{ $o->project->client->name }} · {{ $o->customer_name }} {{ $o->customer_phone }}</span>
                @if ($o->courier || $o->tracking_reference)<span class="secondary">{{ $o->courier }} {{ $o->tracking_reference }}</span>@endif
                <span class="secondary">{{ $o->created_at?->format('d M Y') }}@if ($o->confirmed_at) · confirmed {{ $o->confirmed_at->format('d M') }}@endif @if ($o->dispatched_at) · dispatched {{ $o->dispatched_at->format('d M') }}@endif @if ($o->delivered_at) · delivered {{ $o->delivered_at->format('d M') }}@endif</span></td>
            <td>@foreach ($o->items as $it){{ $it->description }} × {{ $it->quantity }}@if ($it->line) <span class="muted">({{ $it->line->lot->number }})</span>@endif @if ($it->delivered_quantity > 0) → delivered {{ $it->delivered_quantity }}@endif @if ($it->returned_quantity > 0) · returned {{ $it->returned_quantity }}@endif<br>@endforeach</td>
            <td class="num">{{ Money::format($o->delivery_status === 'delivered' ? $o->deliveredValue() : (int) $o->items->sum(fn ($i) => $i->amount())) }}@if ($o->delivery_charge > 0)<span class="secondary">delivery charge {{ Money::format($o->delivery_charge) }} ({{ $o->delivery_charge_bearer }}, {{ str_replace('_', ' ', $o->delivery_charge_status) }})</span>@endif</td>
            <td><span class="badge badge-{{ in_array($o->delivery_status, ['delivered']) ? 'paid' : (in_array($o->delivery_status, ['failed', 'cancelled']) ? 'unpaid' : 'partial') }}">{{ $o->fulfilmentLabel() }}</span></td>
            <td>@if ($o->delivery_status === 'delivered'){{ Money::format($o->commission) }} <span class="secondary">{{ number_format(($o->rate_bps ?? 0) / 100, 2) }}%{{ $o->invoice ? ' · '.$o->invoice->number : '' }}</span>@else — @endif</td>
            <td>{{ $cs['state'] }}@if ($cs['delivered'] > 0)<span class="secondary">{{ Money::format($cs['collected']) }} of {{ Money::format($cs['delivered']) }}</span>@endif</td>
            <td>
                @can('orders.manage')
                    @if ($o->delivery_status === 'pending')
                        <form method="POST" action="{{ route('product.orders.confirm', $o) }}" class="inline-form">@csrf
                            <select name="stock_location_id" aria-label="Reserve from location"><option value="">Any location</option>@foreach (\App\Models\StockLocation::where('is_active', true)->orderBy('name')->get() as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach</select>
                            <button class="btn btn-sm btn-primary">Confirm &amp; reserve</button></form>
                    @endif
                    @if ($o->delivery_status === 'confirmed')
                        <form method="POST" action="{{ route('product.orders.pack', $o) }}" class="inline-form">@csrf<button class="btn btn-sm">Packed</button></form>
                    @endif
                    @if (in_array($o->delivery_status, ['confirmed', 'packed']))
                        <form method="POST" action="{{ route('product.orders.dispatch', $o) }}" class="inline-form">@csrf
                            <input name="courier" size="9" placeholder="courier" maxlength="80" aria-label="Courier"><input name="tracking_reference" size="11" placeholder="tracking no." maxlength="120" aria-label="Tracking reference">
                            <input name="delivery_charge" size="6" inputmode="decimal" placeholder="delivery charge" value="{{ $o->delivery_charge > 0 ? App\Support\Money::toDecimal($o->delivery_charge) : '' }}" aria-label="Delivery charge (recorded once)">
                            <select name="delivery_charge_bearer" aria-label="Who bears the delivery charge"><option value="client">Client bears</option><option value="sellbees">Sellbees bears</option><option value="customer">Customer pays extra</option></select>
                            <select name="delivery_charge_status" aria-label="How the delivery charge is settled"><option value="payable">Not yet paid</option><option value="paid_by_sellbees">Paid by Sellbees</option><option value="courier_deducted">Courier deducts it</option><option value="paid_by_client">Client paid the courier</option></select>
                            <button class="btn btn-sm btn-primary">Dispatched</button></form>
                    @endif
                    @if ($o->delivery_status === 'dispatched')
                        <form method="POST" action="{{ route('product.orders.deliver', $o) }}" class="inline-form">@csrf
                            @foreach ($o->items as $it)<input name="delivered[{{ $it->id }}]" size="5" inputmode="decimal" placeholder="{{ $it->quantity }}" title="Delivered quantity of {{ $it->description }} (blank = all)">@endforeach
                            <button class="btn btn-sm btn-primary">Delivered</button></form>
                        <form method="POST" action="{{ route('product.orders.fail', $o) }}" class="inline-form" data-confirm="Mark this delivery as failed? The parcel comes back into custody and must be inspected before the stock can be sold again. No commission is earned.">@csrf<button class="btn btn-sm btn-danger">Failed</button></form>
                    @endif
                    @if (in_array($o->delivery_status, ['pending', 'confirmed', 'packed']))
                        <form method="POST" action="{{ route('product.orders.cancel', $o) }}" class="inline-form" data-confirm="Cancel this order?">@csrf<button class="btn btn-sm btn-danger">Cancel</button></form>
                    @endif
                    @if ($o->delivery_status === 'delivered')
                        <form method="POST" action="{{ route('product.orders.return', $o) }}" class="inline-form">@csrf
                            @foreach ($o->items as $it)<input name="returned[{{ $it->id }}]" size="5" inputmode="decimal" placeholder="return" title="Units of {{ $it->description }} sent back">@endforeach
                            <button class="btn btn-sm">Customer return</button></form>
                    @endif
                    @if ($o->recoverableCharge() > 0 && ! $o->delivery_invoice_id)
                        @can('commission.manage')<form method="POST" action="{{ route('product.orders.bill-delivery', $o) }}" class="inline-form" data-confirm="Bill the courier charge Sellbees paid to the client? It can be billed once.">@csrf<button class="btn btn-sm">Bill delivery charge {{ Money::format($o->recoverableCharge()) }}</button></form>@endcan
                    @endif
                    @if ($o->delivery_status === 'failed' || ($o->delivery_status === 'delivered' && $o->items->contains(fn ($i) => $i->returned_quantity > 0)))
                        @if ($o->failed_delivery_charge == 0 || $o->return_charge == 0)
                        <form method="POST" action="{{ route('product.orders.courier-charges', $o) }}" class="inline-form">@csrf
                            @if ($o->delivery_status === 'failed' && $o->failed_delivery_charge == 0)<input name="failed_delivery_charge" size="6" inputmode="decimal" placeholder="failed-delivery charge">@endif
                            @if ($o->return_charge == 0 && $o->items->contains(fn ($i) => $i->returned_quantity > 0))<input name="return_charge" size="6" inputmode="decimal" placeholder="return charge">@endif
                            <button class="btn btn-sm">Record courier charge</button></form>
                        @endif
                    @endif
                    @if (in_array($o->delivery_status, ['delivered', 'failed']))
                        @foreach ($o->items as $it)
                            @php($waiting = \App\Services\OrderFulfilment::awaitingInspection($it))
                            @if (bccomp($waiting, '0', 2) > 0)
                                <form method="POST" action="{{ route('product.items.inspect', $it) }}" class="inline-form">@csrf<span class="muted">{{ $waiting }} to inspect:</span>
                                    <input name="usable" size="4" placeholder="usable" required><input name="damaged" size="4" placeholder="damaged" value="0" required><button class="btn btn-sm">Inspected</button></form>
                            @endif
                        @endforeach
                    @endif
                @endcan
                @can('collection.record')
                    @if ($cs['outstanding'] > 0)
                        <form method="POST" action="{{ route('product.orders.collect', $o) }}" class="inline-form" style="margin-top:.3rem">@csrf
                            <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
                            <input name="amount" size="9" inputmode="decimal" value="{{ Money::toDecimal($cs['outstanding']) }}" required title="Money actually collected from the customer">
                            <input type="date" name="collected_on" value="{{ today()->toDateString() }}" required>
                            @if ($accounts->isNotEmpty())<select name="financial_account_id"><option value="">Account…</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>@endif
                            <button class="btn btn-sm btn-primary">Money collected</button></form>
                    @endif
                @endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No stock orders yet.</td></tr>
    @endforelse
    </tbody>
</table></div>
@include('partials.pagination', ['paginator' => $orders])
