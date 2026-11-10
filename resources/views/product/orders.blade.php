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
    @php($cnt = $summary['counts'])
    @php($tot = $summary['totals'])
    <section class="panel">
        <div class="panel-head"><h2>Order summary</h2><span class="sub">for the filters below; delivered values are what was delivered and not returned</span></div>
        <div class="panel-body"><div class="stat-grid">
            @foreach ([['pending', 'Draft', $cnt['draft']], ['confirmed', 'Confirmed', $cnt['confirmed']], ['packed', 'Packed', $cnt['packed']], ['dispatched', 'Dispatched (pending delivery)', $cnt['dispatched']], ['delivered', 'Delivered', $cnt['delivered']], ['failed', 'Failed delivery', $cnt['failed']], ['cancelled', 'Cancelled', $cnt['cancelled']]] as [$key, $label, $n])
                <a class="stat" href="{{ route('product.orders', array_merge(request()->except('page'), ['status' => $key])) }}" style="text-decoration:none;color:inherit"><span class="stat-label">{{ $label }}</span><span class="stat-value">{{ $n }}</span></a>
            @endforeach
        </div>
        <div class="stat-grid" style="margin-top:.6rem">
            <div class="stat"><span class="stat-label">Successful sales value</span><span class="stat-value">{{ Money::format($tot['sales']) }}</span></div>
            <div class="stat"><span class="stat-label">Delivery charges (client-borne, once)</span><span class="stat-value">{{ Money::format($tot['delivery']) }}</span></div>
            <div class="stat"><span class="stat-label">Net successful sales</span><span class="stat-value">{{ Money::format($tot['net']) }}</span></div>
            <div class="stat"><span class="stat-label">Sellbees commission</span><span class="stat-value">{{ Money::format($tot['commission']) }}</span></div>
            <div class="stat"><span class="stat-label">Client entitlement</span><span class="stat-value">{{ Money::format($tot['entitlement']) }}</span></div>
            <div class="stat"><span class="stat-label">Success rate (delivered / delivered + failed)</span><span class="stat-value">{{ $summary['success_rate_bps'] === null ? '—' : number_format($summary['success_rate_bps'] / 100, 1).'%' }}</span></div>
        </div></div>
    </section>

    <section class="panel">
        <form method="GET" class="filters" role="search">
            @if ($project)<input type="hidden" name="project" value="{{ $project->id }}">@endif
            <div class="field grow"><label>Search: order, customer, phone, tracking or project</label><input type="search" name="q" value="{{ request('q') }}"></div>
            <div class="field"><label>Product</label><input type="search" name="product" value="{{ request('product') }}"></div>
            <div class="field"><label>Client</label><select name="client"><option value="">All</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="field"><label>Project</label><select name="fproject"><option value="">All</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(request('fproject') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div class="field"><label>Status</label><select name="status"><option value="">All</option>@foreach ($statuses as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="field"><label>Courier</label><select name="courier"><option value="">All</option>@foreach ($couriers as $cr)<option value="{{ $cr }}" @selected(request('courier') === $cr)>{{ $cr }}</option>@endforeach</select></div>
            <div class="field"><label>Ordered from</label><input type="date" name="from" value="{{ request('from') }}"></div>
            <div class="field"><label>to</label><input type="date" name="to" value="{{ request('to') }}"></div>
            <div class="field"><label>Sort by</label><select name="sort">@foreach ($sorts as $k => $v)<option value="{{ $k }}" @selected(request('sort', 'ordered_on') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="field"><label>Order</label><select name="dir"><option value="desc" @selected(request('dir', 'desc') === 'desc')>Newest / highest first</option><option value="asc" @selected(request('dir') === 'asc')>Oldest / lowest first</option></select></div>
            <div class="field"><label>Per page</label><select name="per_page">@foreach ([25, 50, 100] as $n)<option value="{{ $n }}" @selected((int) request('per_page', 25) === $n)>{{ $n }}</option>@endforeach</select></div>
            <button class="btn">Filter</button> <a class="btn" href="{{ route('product.orders', $project ? ['project' => $project->id] : []) }}">Clear</a>
        </form>
    </section>
    <section class="panel">
        @include('product._orders-table', ['orders' => $orders, 'accounts' => $accounts])
    </section>
</x-layout>
