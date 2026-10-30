<x-layout title="Suppliers">
    <x-page-header title="Suppliers">
        <x-slot:meta>{{ $suppliers->total() }} {{ Str::plural('supplier', $suppliers->total()) }}. Suppliers are archived, never deleted, so lot and payment history stays intact.</x-slot:meta>
        @can('sourcing.manage')
        <x-slot:actions><a href="{{ route('sourcing.suppliers.create') }}" class="btn btn-primary"><x-icon name="plus" /> New supplier</a></x-slot:actions>

        @endcan
    </x-page-header>

    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field grow"><label for="q">Search</label><input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Name, company, category or phone"></div>
            <div class="field"><label for="status">Status</label>
                <select id="status" name="status">
                    @foreach (['active' => 'Active', 'inactive' => 'Archived', 'all' => 'All'] as $k => $label)
                        <option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>
                    @endforeach
                </select></div>
            <button class="btn"><x-icon name="search" class="icon-sm" /> Filter</button>
        </form>
        @if ($suppliers->isEmpty())
            <x-empty-state icon="users" message="No suppliers match." />
        @else
            <div class="table-wrap"><table class="table table-min-640">
                <thead><tr><th>Supplier</th><th>Contact</th><th>Categories</th><th class="num">Products</th><th class="num">Lot lines</th><th>Status</th></tr></thead>
                <tbody>
                @foreach ($suppliers as $s)
                    <tr><td class="primary-cell"><a href="{{ route('sourcing.suppliers.show', $s) }}">{{ $s->name }}</a>@if ($s->company_name)<span class="secondary">{{ $s->company_name }}</span>@endif</td>
                        <td>{{ $s->contact_person ?? '—' }}<span class="secondary">{{ $s->phone }}</span></td>
                        <td>{{ $s->categories ?? '—' }}</td><td class="num">{{ $s->products_count }}</td><td class="num">{{ $s->lot_lines_count }}</td>
                        <td><x-status :value="$s->is_active ? 'active' : 'archived'" /></td></tr>
                @endforeach
                </tbody>
            </table></div>
            @include('partials.pagination', ['paginator' => $suppliers])
        @endif
    </section>
</x-layout>
