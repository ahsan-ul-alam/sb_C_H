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
            </div>
            <div class="field" style="margin-top:.9rem"><label>Notes</label><textarea name="notes" rows="3">{{ old('notes') }}</textarea></div>
            <button class="btn btn-primary" style="margin-top:.9rem">Create lot</button>
        </form>
    </section>
</x-layout>
