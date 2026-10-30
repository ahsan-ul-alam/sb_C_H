@use('App\Support\Money')
<x-layout title="Edit product">
    <x-page-header :title="'Edit '.$product->label()" :crumbs="[['Supplier Products', route('sourcing.products.index')], [$product->supplier->name, null]]">
        <x-slot:meta>Changing the price adds a history entry and applies to future lots only.</x-slot:meta>
    </x-page-header>
    <section class="panel">
        <form method="POST" action="{{ route('sourcing.products.update', $product) }}" class="panel-body">@csrf @method('PUT')
            <input type="hidden" name="supplier_id" value="{{ $product->supplier_id }}">
            <div class="form-grid">
                <div class="field"><label>Product name</label><input name="name" value="{{ old('name', $product->name) }}" required></div>
                <div class="field"><label>SKU / code</label><input name="sku" value="{{ old('sku', $product->sku) }}"></div>
                <div class="field"><label>Category</label><input name="category" value="{{ old('category', $product->category) }}"></div>
                <div class="field"><label>Variant / size / spec</label><input name="variant" value="{{ old('variant', $product->variant) }}"></div>
                <div class="field"><label>Unit</label><input name="unit" value="{{ old('unit', $product->unit) }}" required></div>
                <div class="field"><label>Reference unit price</label><input name="reference_price" inputmode="decimal" value="{{ old('reference_price', Money::toDecimal($product->reference_price)) }}" required></div>
                <div class="field"><label>Minimum order qty</label><input name="min_order_qty" inputmode="decimal" value="{{ old('min_order_qty', $product->min_order_qty) }}"></div>
                <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))> Active</label>
            </div>
            <div class="field" style="margin-top:.9rem"><label>Notes</label><input name="notes" value="{{ old('notes', $product->notes) }}"></div>
            <button class="btn btn-primary" style="margin-top:.9rem">Save</button>
        </form>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Price history</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Effective</th><th class="num">Reference price</th></tr></thead>
            <tbody>@foreach ($product->prices as $price)<tr><td>{{ $price->effective_on->format('j M Y') }}</td><td class="num">{{ Money::format($price->price) }}</td></tr>@endforeach</tbody>
        </table></div>
    </section>
</x-layout>
