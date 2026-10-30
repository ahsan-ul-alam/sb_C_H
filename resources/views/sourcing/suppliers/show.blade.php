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
        <div class="panel-head"><h2>Catalog products</h2> <a class="btn btn-sm" href="{{ route('sourcing.products.index', ['supplier' => $supplier->id]) }}">Manage</a></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Unit</th><th class="num">Reference price</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($products as $p)
                <tr><td>{{ $p->label() }}</td><td>{{ $p->sku ?? '—' }}</td><td>{{ $p->category ?? '—' }}</td><td>{{ $p->unit }}</td><td class="num">{{ Money::format($p->reference_price) }}</td><td><x-status :value="$p->is_active ? 'active' : 'archived'" /></td></tr>
            @empty
                <tr><td colspan="6" class="muted">No products yet.</td></tr>
            @endforelse
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
