<x-layout :title="$supplier->exists ? 'Edit supplier' : 'New supplier'">
    <x-page-header :title="$supplier->exists ? 'Edit '.$supplier->name : 'New supplier'" :crumbs="[['Suppliers', route('sourcing.suppliers.index')], [$supplier->exists ? 'Edit' : 'New', null]]" />
    <section class="panel">
        <form method="POST" action="{{ $supplier->exists ? route('sourcing.suppliers.update', $supplier) : route('sourcing.suppliers.store') }}" class="panel-body">@csrf
            @if ($supplier->exists) @method('PUT') @endif
            <div class="form-grid">
                @foreach (['name' => 'Supplier name', 'company_name' => 'Company / business name', 'contact_person' => 'Contact person', 'phone' => 'Phone', 'email' => 'Email', 'address' => 'Address', 'categories' => 'Product categories'] as $field => $label)
                    <div class="field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $supplier->$field) }}" @required($field === 'name')></div>
                @endforeach
            </div>
            @can('sourcing.supplier.secrets')
                <div class="field" style="margin-top:.9rem"><label for="payment_info">Payment information (restricted)</label><textarea id="payment_info" name="payment_info" rows="3">{{ old('payment_info', $supplier->payment_info) }}</textarea></div>
            @endcan
            <div class="field" style="margin-top:.9rem"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3">{{ old('notes', $supplier->notes) }}</textarea></div>
            <button class="btn btn-primary" style="margin-top:.9rem">Save supplier</button>
        </form>
    </section>
</x-layout>
