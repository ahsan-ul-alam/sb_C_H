<x-layout title="New sourcing lot">
    <x-page-header title="New sourcing lot" :crumbs="[['Sourcing Lots', route('sourcing.lots.index')], ['New', null]]">
        <x-slot:meta>A lot does not need a client yet. Clients are attached later through allocations.</x-slot:meta>
    </x-page-header>
    <section class="panel">
        <form method="POST" action="{{ route('sourcing.lots.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Title</label><input name="title" value="{{ old('title') }}" required placeholder="e.g. Packaging boxes — October"></div>
                <div class="field"><label>Shared-cost allocation</label>
                    <select name="allocation_basis"><option value="value">By purchase value (default)</option><option value="quantity" @selected(old('allocation_basis') === 'quantity')>By quantity</option></select></div>
                <div class="field"><label>Purchase order no.</label><input name="purchase_order" value="{{ old('purchase_order') }}" placeholder="Your PO number sent to the supplier"></div>
                <div class="field"><label>Client project this is for</label>
                    <select name="project_id"><option value="">Choose later</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id') === $p->id)>{{ $p->name }} — {{ $p->client->name }}</option>@endforeach</select></div>
            </div>
            <div class="field" style="margin-top:.9rem"><label>Notes</label><textarea name="notes" rows="3">{{ old('notes') }}</textarea></div>
            <button class="btn btn-primary" style="margin-top:.9rem">Create lot</button>
        </form>
    </section>
</x-layout>
