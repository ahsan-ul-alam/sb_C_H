@use('App\Support\Money')
<x-layout :title="$supplier->name">
    <x-page-header :title="$supplier->name" :crumbs="[['Suppliers', route('sourcing.suppliers.index')], [$supplier->name, null]]">
        <x-slot:badges><x-status :value="$supplier->is_active ? 'active' : 'archived'" /></x-slot:badges>
        <x-slot:meta>{{ $supplier->company_name }} {{ $supplier->contact_person ? '· '.$supplier->contact_person : '' }} {{ $supplier->phone ? '· '.$supplier->phone : '' }} {{ $supplier->email ? '· '.$supplier->email : '' }}</x-slot:meta>
        @can('sourcing.manage')
        <x-slot:actions>
            <a href="{{ route('sourcing.suppliers.edit', $supplier) }}" class="btn">Edit</a>
            <form method="POST" action="{{ route('sourcing.suppliers.archive', $supplier) }}" style="display:inline">@csrf @method('PATCH')<button class="btn">{{ $supplier->is_active ? 'Archive' : 'Restore' }}</button></form>
        </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Purchase value (cost)</div><div class="value">{{ Money::format($payable['purchases']) }}</div></div>
        <div class="metric"><div class="label">Paid to supplier</div><div class="value">{{ Money::format($payable['paid']) }}</div></div>
        <div class="metric @if ($payable['outstanding'] > 0) is-danger @endif"><div class="label">Outstanding payable</div><div class="value">{{ Money::format($payable['outstanding']) }}</div></div>
    </div>

    <section class="panel">
        <div class="panel-head"><h2>Details</h2></div>
        <div class="panel-body">
            <p><strong>Address:</strong> {{ $supplier->address ?? '—' }} &nbsp; <strong>Categories:</strong> {{ $supplier->categories ?? '—' }}</p>
            @can('sourcing.supplier.secrets')<p><strong>Payment information:</strong> {{ $supplier->payment_info ?? '—' }}</p>@endcan
            @if ($supplier->notes)<p class="muted">{{ $supplier->notes }}</p>@endif
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Products &amp; base prices</h2><span class="sub">A price change applies to future lots only; saved lots keep the price they were made with.</span></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Product</th><th>SKU (optional)</th><th class="num">Base purchase price</th><th>Active</th><th></th></tr></thead>
            <tbody>
            @foreach ($products as $p)
                <tr>
                    @can('sourcing.manage')
                        <td><input form="p-{{ $p->id }}" name="name" value="{{ $p->name }}" required></td>
                        <td><input form="p-{{ $p->id }}" name="sku" value="{{ $p->sku }}" size="10"></td>
                        <td class="num"><input form="p-{{ $p->id }}" name="reference_price" inputmode="decimal" size="9" value="{{ Money::toDecimal($p->reference_price) }}" required></td>
                        <td><input form="p-{{ $p->id }}" type="checkbox" name="is_active" value="1" @checked($p->is_active)></td>
                        <td class="num"><form id="p-{{ $p->id }}" method="POST" action="{{ route('sourcing.products.update', $p) }}">@csrf @method('PUT')
                            <input type="hidden" name="inline" value="1"><input type="hidden" name="supplier_id" value="{{ $supplier->id }}"><input type="hidden" name="unit" value="{{ $p->unit }}"><button class="btn btn-sm">Save</button></form></td>
                    @else
                        <td>{{ $p->label() }}</td><td>{{ $p->sku ?? '—' }}</td><td class="num">{{ Money::format($p->reference_price) }}</td><td><x-status :value="$p->is_active ? 'active' : 'archived'" /></td><td></td>
                    @endcan
                </tr>
            @endforeach
            @can('sourcing.manage')
                <tr>
                    <td><input form="p-new" name="name" placeholder="New product name" required></td>
                    <td><input form="p-new" name="sku" size="10"></td>
                    <td class="num"><input form="p-new" name="reference_price" inputmode="decimal" size="9" placeholder="0.00" required></td>
                    <td></td>
                    <td class="num"><form id="p-new" method="POST" action="{{ route('sourcing.products.store') }}">@csrf<input type="hidden" name="supplier_id" value="{{ $supplier->id }}"><button class="btn btn-sm btn-primary">Add product</button></form></td>
                </tr>
            @else
                @if ($products->isEmpty())<tr><td colspan="5" class="muted">No products yet.</td></tr>@endif
            @endcan
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Sourcing lots</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Lot</th><th>Title</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($lots as $lot)
                <tr><td><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a></td><td>{{ $lot->title }}</td><td><x-status :value="$lot->status" /></td></tr>
            @empty
                <tr><td colspan="3" class="muted">No lots yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Payment history</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Lot</th><th>Method / reference</th><th class="num">Amount</th><th>State</th></tr></thead>
            <tbody>
            @forelse ($payments as $pay)
                <tr><td>{{ $pay->paid_on->format('j M Y') }}</td><td>{{ $pay->lot?->number }}</td><td>{{ $pay->method }} {{ $pay->reference }}</td><td class="num">{{ Money::format($pay->amount) }}</td><td><x-status :value="$pay->isVoided() ? 'voided' : 'paid'" /></td></tr>
            @empty
                <tr><td colspan="5" class="muted">No payments yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
