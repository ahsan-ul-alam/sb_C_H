<x-layout :title="$supplier->exists ? 'Edit supplier' : 'New supplier'">
    <x-page-header :title="$supplier->exists ? 'Edit '.$supplier->name : 'New supplier'" :crumbs="[['Suppliers', route('sourcing.suppliers.index')], [$supplier->exists ? 'Edit' : 'New', null]]">
        <x-slot:meta>Just the essentials. You add the supplier's products and base prices on its profile page after saving.</x-slot:meta>
    </x-page-header>
    <section class="panel">
        <form method="POST" action="{{ $supplier->exists ? route('sourcing.suppliers.update', $supplier) : route('sourcing.suppliers.store') }}" class="panel-body">@csrf
            @if ($supplier->exists) @method('PUT') @endif
            <div class="form-grid">
                @foreach (['name' => 'Supplier name', 'phone' => 'Phone', 'address' => 'Address'] as $field => $label)
                    <div class="field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $supplier->$field) }}" @required($field === 'name')></div>
                @endforeach
            </div>
            @can('sourcing.supplier.secrets')
                <div class="field" style="margin-top:.9rem"><label for="payment_info">Payment information (optional, restricted)</label><textarea id="payment_info" name="payment_info" rows="2">{{ old('payment_info', $supplier->payment_info) }}</textarea></div>
            @endcan
            <details style="margin-top:.7rem" @if ($supplier->company_name || $supplier->contact_person || $supplier->email || $supplier->categories || $supplier->notes) open @endif><summary>More details (optional)</summary>
                <div class="form-grid" style="margin-top:.7rem">
                    @foreach (['company_name' => 'Company / business name', 'contact_person' => 'Contact person', 'email' => 'Email', 'categories' => 'Product categories'] as $field => $label)
                        <div class="field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $supplier->$field) }}"></div>
                    @endforeach
                </div>
                <div class="field" style="margin-top:.9rem"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2">{{ old('notes', $supplier->notes) }}</textarea></div>
            </details>
            <button class="btn btn-primary" style="margin-top:.9rem">Save supplier</button>
        </form>
    </section>
</x-layout>
