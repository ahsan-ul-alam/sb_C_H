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
        <p class="muted" style="padding:0 1rem"><a href="{{ route('product.projects.show', $project) }}">Open this project's inventory and orders dashboard →</a></p>
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
                <div class="field"><label>Delivery charge (information only)</label><input name="delivery_charge" value="{{ old('delivery_charge') }}" inputmode="decimal">@error('delivery_charge') <span class="error">{{ $message }}</span> @enderror</div>
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
        @include('product._orders-table', ['orders' => $orders, 'accounts' => $accounts])
    </section>
</x-layout>
