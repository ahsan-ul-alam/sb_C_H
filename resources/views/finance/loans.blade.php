@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Loans">
    <x-page-header title="Loans">
        <x-slot:meta>Borrowed money is a liability: receiving it adds cash and a loan payable, repaying principal removes both. Neither is income or owner capital, and neither changes profit. Interest is a cost and is recorded as an expense.</x-slot:meta>
    </x-page-header>

    @can('loan.record')
    <section class="panel">
        <div class="panel-head"><h2>New loan</h2></div>
        <form method="POST" action="{{ route('finance.loans.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Lender</label><input name="lender" value="{{ old('lender') }}" required></div>
                <div class="field"><label>Reference</label><input name="reference" value="{{ old('reference') }}"></div>
                <div class="field full"><label>Notes</label><input name="notes" value="{{ old('notes') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Create</button>
        </form>
    </section>
    @endcan

    @forelse ($loans as $loan)
    <section class="panel">
        <div class="panel-head"><h2>{{ $loan->lender }}@if ($loan->reference) <span class="muted">· {{ $loan->reference }}</span>@endif</h2><div class="strong">Principal owed {{ Money::format($loan->outstanding(), $cur) }}</div></div>
        @can('loan.record')
        <form method="POST" action="{{ route('finance.loans.entries.store', $loan) }}" class="panel-body" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:end">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="field"><label>Kind</label><select name="kind"><option value="receipt">Loan received</option><option value="principal">Principal repaid</option></select></div>
            <div class="field"><label>Amount</label><input name="amount" inputmode="decimal" required style="max-width:9rem"></div>
            <div class="field"><label>Date</label><input type="date" name="entry_date" value="{{ today()->toDateString() }}" required></div>
            <div class="field"><label>Account</label><select name="financial_account_id"><option value="">Default account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            <button class="btn btn-primary">Record</button>
        </form>
        <form method="POST" action="{{ route('finance.loans.interest', $loan) }}" class="panel-body" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:end">@csrf
            <div class="field"><label>Interest paid</label><input name="amount" inputmode="decimal" required style="max-width:9rem"></div>
            <div class="field"><label>Date</label><input type="date" name="spent_on" value="{{ today()->toDateString() }}" required></div>
            <div class="field"><label>Account</label><select name="financial_account_id"><option value="">Default account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            <button class="btn">Record interest as expense</button>
        </form>
        @endcan
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Kind</th><th>Account</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($loan->entries->sortByDesc('entry_date') as $e)
                <tr><td>{{ $e->entry_date->format('j M Y') }}</td><td>{{ $e->kind === 'receipt' ? 'Loan received' : 'Principal repaid' }}</td><td class="muted">{{ $e->account?->name }}</td><td class="num">{{ Money::format($e->amount) }}</td><td><x-status :value="$e->status" /></td>
                    <td class="num">
                        @can('loan.verify')
                            @if ($e->status === 'pending')
                                @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('finance.loans.entries.decide', $e) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
                            @elseif ($e->status === 'verified' && $e->created_by !== auth()->id())
                                <details><summary class="btn btn-sm">Reverse</summary><form method="POST" action="{{ route('finance.loans.entries.reverse', $e) }}" style="display:grid; gap:.4rem; margin-top:.4rem">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-sm btn-danger">Reverse</button></form></details>
                            @endif
                        @endcan
                    </td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
    @empty
        <section class="panel"><div class="panel-body muted">No loans recorded.</div></section>
    @endforelse
</x-layout>