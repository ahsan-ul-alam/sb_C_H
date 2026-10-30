@use('App\Support\Money')
<x-layout title="Expenses">
    <x-page-header title="Expenses">
        <x-slot:meta>Income comes from ClientHub payments ({{ config('finance.currency') }} projects). Verified expenses are posted to the journal.</x-slot:meta>
    </x-page-header>

    @can('expense.record')
    <section class="panel">
        <div class="panel-head"><h2>Record expense</h2></div>
        <form method="POST" action="{{ route('finance.expenses.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Category</label><input name="category" value="{{ old('category') }}" required></div>
                <div class="field"><label>Description</label><input name="description" value="{{ old('description') }}" required></div>
                <div class="field"><label>Amount</label><input name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
                <label class="check"><input type="checkbox" name="is_asset" value="1" @checked(old('is_asset'))> Capital purchase (office equipment, other lasting assets) — recorded as an asset, not an operating expense</label>
                <div class="field"><label>Date</label><input type="date" name="spent_on" value="{{ old('spent_on', today()->toDateString()) }}" required></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Record</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($expenses as $e)
                <tr><td>{{ $e->spent_on->format('j M Y') }}</td><td>{{ $e->category }}</td><td>{{ $e->description }}@if ($e->is_asset) <span class="badge badge-partial">Capital asset</span>@endif @if ($e->reverses_id) <span class="badge badge-partial">Correction ({{ $e->correction_type }})</span>@endif</td>
                    <td class="num">{{ Money::format($e->amount) }}</td><td><x-status :value="$e->status" />@if ($e->auto_policy_id)<span class="secondary">Automatic — standing policy #{{ $e->auto_policy_id }}</span>@endif</td>
                    <td class="num">
                        @if ($e->status === 'pending')
                            @can('expense.verify')
                                @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)
                                    <form method="POST" action="{{ route('finance.expenses.decide', $e) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>
                                @endforeach
                            @endcan
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="6" class="muted">No expenses yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $expenses])
    </section>
</x-layout>
