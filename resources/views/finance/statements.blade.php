@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Account Statements">
    <x-page-header title="Account Statements">
        <x-slot:meta>The balance the books calculate is not verified cash. Enter the balance printed on a bank, bKash/Nagad/Rocket statement or a cash count, with its reference; a second person verifies it. Whatever separates it from the books must be explained item by item. Any remainder, even 0.01, is an unexplained difference and blocks period close and profit allocation.</x-slot:meta>
    </x-page-header>

    @if ($blockers)
    <section class="panel"><div class="panel-body"><strong>Blocking close and allocation:</strong>
        <ul>@foreach ($blockers as $b)<li>{{ $b }}</li>@endforeach</ul></div></section>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Books vs verified statements</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Account</th><th class="num">Books today (calculated)</th><th>Latest verified statement</th><th class="num">Statement balance</th><th class="num">Books at that date</th><th class="num">Explained items</th><th class="num">Unexplained</th><th>State</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['account']->name }}</td>
                    <td class="num">{{ Money::format($r['ledger'], $cur) }}</td>
                    @if ($r['statement'])
                        <td>{{ $r['statement']->statement_date->format('j M Y') }} <span class="muted">· {{ $r['statement']->reference }}</span></td>
                        <td class="num">{{ Money::format($r['position']['statement'], $cur) }}</td>
                        <td class="num">{{ Money::format($r['position']['ledger'], $cur) }}</td>
                        <td class="num">{{ Money::format($r['position']['items_total'], $cur) }}</td>
                        <td class="num strong">{{ Money::format($r['position']['unexplained'], $cur) }}</td>
                        <td>{{ $r['position']['state'] }}</td>
                    @else
                        <td colspan="6" class="muted">No verified statement: this balance is calculated, not verified.</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>

    @can('account.manage')
    <section class="panel">
        <div class="panel-head"><h2>Record a statement balance</h2></div>
        <form method="POST" action="{{ route('finance.statements.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Account</label><select name="financial_account_id" required>@foreach ($rows as $r)<option value="{{ $r['account']->id }}">{{ $r['account']->name }}</option>@endforeach</select></div>
                <div class="field"><label>Statement date</label><input type="date" name="statement_date" max="{{ today()->toDateString() }}" required></div>
                <div class="field"><label>Balance on the statement</label><input name="statement_balance" inputmode="decimal" required></div>
                <div class="field"><label>Reference (statement no., screenshot id, count sheet)</label><input name="reference" required></div>
                <div class="field full"><label>Notes</label><input name="notes"></div>
            </div>
            <p class="muted" style="margin-top:.8rem">Reconciling items (amount added to the statement balance to reach the books; use a minus sign for a payment not yet cleared):</p>
            @foreach (range(0, 2) as $i)
            <div style="display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.4rem">
                <select name="items[{{ $i }}][kind]">@foreach ($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                <input name="items[{{ $i }}][amount]" inputmode="decimal" placeholder="Amount" style="max-width:9rem">
                <input name="items[{{ $i }}][description]" placeholder="Description" style="flex:1; min-width:12rem">
            </div>
            @endforeach
            <button class="btn btn-primary" style="margin-top:.9rem">Record</button>
        </form>
    </section>
    @endcan

    @if ($pending->isNotEmpty())
    <section class="panel">
        <div class="panel-head"><h2>Awaiting verification</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Account</th><th>Date</th><th>Reference</th><th class="num">Statement balance</th><th></th></tr></thead>
            <tbody>
            @foreach ($pending as $s)
                <tr><td>{{ $s->account->name }}</td><td>{{ $s->statement_date->format('j M Y') }}</td><td class="muted">{{ $s->reference }}</td><td class="num">{{ Money::format($s->statement_balance, $cur) }}</td>
                    <td class="num">@can('reconcile.review')
                        @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('finance.statements.decide', $s) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
                    @endcan</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
    @endif
</x-layout>
