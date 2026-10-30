@use('App\Support\Money')
<x-layout title="Supplier Products">
    <x-page-header title="Supplier Products">
        <x-slot:meta>Reference prices are defaults for NEW lots only. Existing lots keep the price they were created with.</x-slot:meta>
    </x-page-header>

    @can('sourcing.manage')
    <section class="panel">
        <div class="panel-head"><h2>Add product</h2></div>
        <form method="POST" action="{{ route('sourcing.products.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Supplier</label>
                    <select name="supplier_id" required>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id', request('supplier')) === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                <div class="field"><label>Product name</label><input name="name" value="{{ old('name') }}" required></div>
                <div class="field"><label>SKU / code</label><input name="sku" value="{{ old('sku') }}"></div>
                <div class="field"><label>Category</label><input name="category" value="{{ old('category') }}"></div>
                <div class="field"><label>Variant / size / spec</label><input name="variant" value="{{ old('variant') }}"></div>
                <div class="field"><label>Unit</label><input name="unit" value="{{ old('unit', 'pcs') }}" required></div>
                <div class="field"><label>Reference unit price</label><input name="reference_price" inputmode="decimal" value="{{ old('reference_price') }}" required></div>
                <div class="field"><label>Minimum order qty</label><input name="min_order_qty" inputmode="decimal" value="{{ old('min_order_qty') }}"></div>
            </div>
            <div class="field" style="margin-top:.9rem"><label>Notes</label><input name="notes" value="{{ old('notes') }}"></div>
            <button class="btn btn-primary" style="margin-top:.9rem">Add product</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field grow"><label for="q">Search</label><input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Name, SKU or category"></div>
            <div class="field"><label>Supplier</label><select name="supplier"><option value="">All</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="field"><label>Status</label><select name="status"><option value="active" @selected(request('status', 'active') === 'active')>Active</option><option value="all" @selected(request('status') === 'all')>All</option></select></div>
            <button class="btn">Filter</button>
        </form>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Product</th><th>Supplier</th><th>SKU</th><th>Category</th><th>Unit</th><th class="num">Reference price</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($products as $p)
                <tr><td>{{ $p->label() }}</td><td>{{ $p->supplier->name }}</td><td>{{ $p->sku ?? '—' }}</td><td>{{ $p->category ?? '—' }}</td><td>{{ $p->unit }}</td>
                    <td class="num">{{ Money::format($p->reference_price) }}</td><td><x-status :value="$p->is_active ? 'active' : 'archived'" /></td>
                    <td class="num">@can('sourcing.manage')<a class="btn btn-sm" href="{{ route('sourcing.products.edit', $p) }}">Edit</a>@endcan</td></tr>
            @empty
                <tr><td colspan="8" class="muted">No products yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $products])
    </section>
</x-layout>
