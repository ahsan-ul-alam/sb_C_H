@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Loans Receivable">
    <x-page-header title="Loans Receivable">
        <x-slot:meta>Money lent out that must be paid back. A loan is created by reclassifying an expense that was really a loan: no new cash moves, the original disbursement stays exactly as recorded, and the amount leaves expenses and becomes an asset. A repayment returns principal to cash and never counts as revenue or profit.</x-slot:meta>
    </x-page-header>

    @can('loan.record')
    <section class="panel">
        <div class="panel-head"><h2>Reclassify an expense as a loan</h2></div>
        <form method="POST" action="{{ route('finance.loans-receivable.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field full"><label>Expense (verified, fully paid)</label>
                    <select name="expense_id" required><option value="">Choose…</option>@foreach ($candidates as $e)<option value="{{ $e->id }}">#{{ $e->id }} · {{ $e->spent_on->format('j M Y') }} · {{ $e->category }}: {{ $e->description }} · {{ Money::format($e->amount) }}</option>@endforeach</select></div>
                <div class="field"><label>Borrower (partner)</label><select name="borrower_partner_id"><option value="">Not a partner</option>@foreach ($partners as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label>Borrower name (if not a partner)</label><input name="borrower_name" value="{{ old('borrower_name') }}"></div>
                <div class="field full"><label>Notes</label><input name="notes" value="{{ old('notes') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Propose</button>
        </form>
    </section>
    @endcan

    @forelse ($loans as $loan)
    <section class="panel">
        <div class="panel-head">
            <h2>{{ $loan->borrower_name }} <span class="muted">· expense #{{ $loan->expense_id }} · lent {{ $loan->lent_on->format('j M Y') }}</span> <x-status :value="$loan->status" /></h2>
            <div class="strong">Lent {{ Money::format($loan->principal, $cur) }} · outstanding {{ Money::format($loan->outstanding(), $cur) }}</div>
        </div>
        @if ($loan->notes)<div class="panel-body muted">{{ $loan->notes }}</div>@endif
        @can('loan.verify')
            @if ($loan->status === 'pending')
            <div class="panel-body">
                @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('finance.loans-receivable.decide', $loan) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
            </div>
            @elseif ($loan->status === 'verified' && $loan->created_by !== auth()->id())
            <div class="panel-body"><details><summary class="btn btn-sm">Reverse reclassification</summary><form method="POST" action="{{ route('finance.loans-receivable.reverse', $loan) }}" style="display:grid; gap:.4rem; margin-top:.4rem">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-sm btn-danger">Reverse</button></form></details></div>
            @endif
        @endcan
        @if ($loan->status === 'verified')
        @can('loan.record')
        <form method="POST" action="{{ route('finance.loans-receivable.repay', $loan) }}" class="panel-body" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:end">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="field"><label>Principal repaid</label><input name="amount" inputmode="decimal" required style="max-width:9rem"></div>
            <div class="field"><label>Date received</label><input type="date" name="repaid_on" required></div>
            <div class="field"><label>Received into</label><select name="financial_account_id"><option value="">Default account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            <div class="field"><label>Reference</label><input name="reference" style="max-width:10rem"></div>
            <button class="btn btn-primary">Record repayment</button>
        </form>
        @endcan
        @endif
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Received into</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($loan->repayments->sortByDesc('repaid_on') as $r)
                <tr><td>{{ $r->repaid_on->format('j M Y') }}</td><td class="muted">{{ $r->account?->name }}</td><td class="muted">{{ $r->reference }}</td><td class="num">{{ Money::format($r->amount) }}</td><td><x-status :value="$r->status" /></td>
                    <td class="num">
                        @can('loan.verify')
                            @if ($r->status === 'pending')
                                @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('finance.loans-receivable.repayments.decide', $r) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
                            @elseif ($r->status === 'verified' && $r->created_by !== auth()->id())
                                <details><summary class="btn btn-sm">Reverse</summary><form method="POST" action="{{ route('finance.loans-receivable.repayments.reverse', $r) }}" style="display:grid; gap:.4rem; margin-top:.4rem">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-sm btn-danger">Reverse</button></form></details>
                            @endif
                        @endcan
                    </td></tr>
            @empty
                <tr><td colspan="6" class="muted">No repayments yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    @empty
        <section class="panel"><div class="panel-body muted">No loans receivable recorded.</div></section>
    @endforelse
</x-layout>
