@use('App\Support\Money')
@use('Illuminate\Support\Str')
<x-layout title="Orders & Delivery">
    <x-page-header title="Orders & Delivery">
        <x-slot:meta>Record the customer order, confirm it to reserve the client's stock, then update the delivery. Commission is worked out only on what is really delivered; money counts only when you record that it was collected.</x-slot:meta>
    </x-page-header>

    @can('orders.manage')
    <section class="panel">
        <div class="panel-head"><h2>New order</h2></div>
        <form method="GET" class="filters"><div class="field grow"><label>1. Client project</label>
            <select name="project" onchange="this.form.submit()"><option value="">Choose…</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected($project?->id === $p->id)>{{ $p->client->name }} — {{ $p->name }}</option>@endforeach</select></div>
            <noscript><button class="btn">Load stock</button></noscript></form>
        @if ($project)
        <form method="POST" action="{{ route('product.orders.store') }}" class="panel-body">@csrf
            <input type="hidden" name="project_id" value="{{ $project->id }}">
            <div class="form-grid">
                <div class="field"><label>Order reference</label><input name="reference" value="{{ old('reference') }}" required></div>
                <div class="field"><label>Order date</label><input type="date" name="ordered_on" value="{{ today()->toDateString() }}" required></div>
                <div class="field"><label>Customer name</label><input name="customer_name" value="{{ old('customer_name') }}"></div>
                <div class="field"><label>Customer phone</label><input name="customer_phone" value="{{ old('customer_phone') }}"></div>
                <div class="field"><label>Delivery address</label><input name="customer_address" value="{{ old('customer_address') }}"></div>
                <div class="field"><label>Sales channel</label><select name="sales_channel"><option value="">—</option>@foreach (\App\Http\Controllers\ProductOperationsController::CHANNELS as $k => $v)<option value="{{ $k }}" @selected(old('sales_channel') === $k)>{{ $v }}</option>@endforeach</select>@error('sales_channel') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label>Payment method</label><select name="payment_method"><option value="">—</option>@foreach (\App\Http\Controllers\ProductOperationsController::PAYMENT_METHODS as $k => $v)<option value="{{ $k }}" @selected(old('payment_method') === $k)>{{ $v }}</option>@endforeach</select>@error('payment_method') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label>Delivery method</label><select name="delivery_method"><option value="">—</option>@foreach (\App\Http\Controllers\ProductOperationsController::DELIVERY_METHODS as $k => $v)<option value="{{ $k }}" @selected(old('delivery_method') === $k)>{{ $v }}</option>@endforeach</select>@error('delivery_method') <span class="error">{{ $message }}</span> @enderror</div>
                @can('orders.discount')
                <div class="field"><label>Discount reason (needed if any line has a discount)</label><input name="discount_reason" value="{{ old('discount_reason') }}">@error('discount_reason') <span class="error">{{ $message }}</span> @enderror</div>
                @endcan
            </div>
            @if ($stock->isEmpty())
                <p class="muted" style="margin-top:.7rem">This client has no available stock. Stock appears here once a lot's arrival is recorded and the lot is stated as client-owned.</p>
            @else
            <div class="table-wrap" style="margin-top:.7rem"><table class="table">
                <thead><tr><th>Product (available)</th><th class="num">Quantity</th><th class="num">Selling price / unit</th>@can('orders.discount')<th class="num">Discount / unit</th>@endcan</tr></thead>
                <tbody>
                @foreach (range(0, 3) as $i)
                    <tr><td><select name="items[{{ $i }}][product_id]"><option value="">—</option>@foreach ($stock as $pid => $s)<option value="{{ $pid }}">{{ $s['name'] }} ({{ $s['available'] }})</option>@endforeach</select></td>
                        <td class="num"><input name="items[{{ $i }}][quantity]" size="7" inputmode="decimal"></td><td class="num"><input name="items[{{ $i }}][price]" size="9" inputmode="decimal"></td>@can('orders.discount')<td class="num"><input name="items[{{ $i }}][discount]" size="7" inputmode="decimal"></td>@endcan</tr>
                @endforeach
                </tbody>
            </table></div>
            <button class="btn btn-primary" style="margin-top:.7rem">Record order</button>
            @endif
        </form>
        @endif
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Order</th><th>Products</th><th class="num">Order value</th><th>Delivery</th><th>Commission</th><th>Collection</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse ($orders as $o)
                @php($cs = \App\Services\OrderFulfilment::collectionState($o))
                <tr>
                    <td class="primary-cell">{{ $o->reference }}<span class="secondary">{{ $o->project->client->name }} · {{ $o->customer_name }} {{ $o->customer_phone }}</span></td>
                    <td>@foreach ($o->items as $it){{ $it->description }} × {{ $it->quantity }}@if ($it->line) <span class="muted">({{ $it->line->lot->number }})</span>@endif @if ($it->delivered_quantity > 0) → delivered {{ $it->delivered_quantity }}@endif @if ($it->returned_quantity > 0) · returned {{ $it->returned_quantity }}@endif<br>@endforeach</td>
                    <td class="num">{{ Money::format($o->delivery_status === 'delivered' ? $o->deliveredValue() : (int) $o->items->sum(fn ($i) => $i->amount())) }}</td>
                    <td><span class="badge badge-{{ in_array($o->delivery_status, ['delivered']) ? 'paid' : (in_array($o->delivery_status, ['failed', 'cancelled']) ? 'unpaid' : 'partial') }}">{{ ucfirst($o->delivery_status) }}</span></td>
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
                                <form method="POST" action="{{ route('product.orders.dispatch', $o) }}" class="inline-form">@csrf<button class="btn btn-sm btn-primary">Dispatched</button></form>
                            @endif
                            @if ($o->delivery_status === 'dispatched')
                                <form method="POST" action="{{ route('product.orders.deliver', $o) }}" class="inline-form">@csrf
                                    @foreach ($o->items as $it)<input name="delivered[{{ $it->id }}]" size="5" inputmode="decimal" placeholder="{{ $it->quantity }}" title="Delivered quantity of {{ $it->description }} (blank = all)">@endforeach
                                    <button class="btn btn-sm btn-primary">Delivered</button></form>
                                <form method="POST" action="{{ route('product.orders.fail', $o) }}" class="inline-form" data-confirm="Mark this delivery as failed? The stock goes back to available and no commission is earned.">@csrf<button class="btn btn-sm btn-danger">Failed</button></form>
                            @endif
                            @if (in_array($o->delivery_status, ['pending', 'confirmed']))
                                <form method="POST" action="{{ route('product.orders.cancel', $o) }}" class="inline-form" data-confirm="Cancel this order?">@csrf<button class="btn btn-sm btn-danger">Cancel</button></form>
                            @endif
                            @if ($o->delivery_status === 'delivered')
                                <form method="POST" action="{{ route('product.orders.return', $o) }}" class="inline-form">@csrf
                                    @foreach ($o->items as $it)<input name="returned[{{ $it->id }}]" size="5" inputmode="decimal" placeholder="return" title="Units of {{ $it->description }} sent back">@endforeach
                                    <button class="btn btn-sm">Customer return</button></form>
                                @foreach ($o->items as $it)
                                    @php($waiting = (string) \App\Models\StockMovement::where('client_order_item_id', $it->id)->sum('d_returned'))
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
    </section>
</x-layout>
