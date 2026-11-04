@use('App\Support\Money')
<x-layout title="Ledger Reports">
    <x-page-header title="Ledger Reports">
        <x-slot:meta>Accrual basis, read from the immutable ledger: {{ $from }} to {{ $to }}. These are the ledger's own figures next to the existing monthly profit; the bridge below shows exactly what differs. Amounts that are not yet decided or reconciled are listed under Exceptions, not folded into the totals.</x-slot:meta>
    </x-page-header>

    <section class="panel"><form method="GET" class="panel-body" style="display:flex; gap:.6rem; flex-wrap:wrap; align-items:end">
        <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="{{ $from }}"></div>
        <div class="field"><label for="to">To (as of)</label><input type="date" id="to" name="to" value="{{ $to }}"></div>
        <button class="btn btn-primary">Show</button>
    </form></section>

    <section class="panel">
        <div class="panel-head"><h2>Exceptions</h2><span class="badge badge-{{ $exceptions === [] ? 'paid' : 'partial' }}">{{ $exceptions === [] ? 'None' : count($exceptions) }}</span></div>
        <div class="panel-body">@forelse ($exceptions as $e)<p><strong>{{ $e['area'] }}</strong> · {{ $e['message'] }}</p>@empty<p class="muted">Every reconciliation agrees and nothing is waiting.</p>@endforelse</div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Operational profit (ledger)</h2></div>
        <div class="table-wrap"><table class="table"><tbody>
            <tr><td>Income</td><td class="num">{{ Money::format($profit['income']) }}</td></tr>
            <tr><td>Other income</td><td class="num">{{ Money::format($profit['other_income']) }}</td></tr>
            <tr><td>Operating expense</td><td class="num">{{ Money::format(-$profit['expense']) }}</td></tr>
            <tr><th>Ledger profit before inventory results</th><th class="num">{{ Money::format($profit['ledger_profit']) }}</th></tr>
            @foreach ($labels as $account => $label)<tr><td>{{ $label }}</td><td class="num">{{ Money::format(-$profit['inventory'][$account]) }}</td></tr>@endforeach
            <tr><th>Operational profit</th><th class="num">{{ Money::format($profit['operational_profit']) }}</th></tr>
        </tbody></table></div>
        <div class="panel-body muted">{{ $profit['basis'] }}</div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Cash flow</h2><span class="badge badge-{{ $cash['agrees'] ? 'paid' : 'partial' }}">{{ $cash['agrees'] ? 'Agrees with the ledger' : 'Differs from the ledger' }}</span></div>
        <div class="table-wrap"><table class="table"><thead><tr><th></th><th class="num">In</th><th class="num">Out</th><th class="num">Net</th></tr></thead><tbody>
            <tr><td>Opening cash</td><td></td><td></td><td class="num">{{ Money::format($cash['opening']) }}</td></tr>
            @foreach ($cash['sections'] as $name => $s)<tr><td>{{ ucfirst($name) }}</td><td class="num">{{ Money::format($s['in']) }}</td><td class="num">{{ Money::format(-$s['out']) }}</td><td class="num">{{ Money::format($s['net']) }}</td></tr>@endforeach
            <tr><th>Closing cash</th><th></th><th></th><th class="num">{{ Money::format($cash['closing']) }}</th></tr>
        </tbody></table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Trial balance as of {{ $trial['as_of'] }}</h2><span class="badge badge-{{ $trial['balanced'] ? 'paid' : 'partial' }}">{{ $trial['balanced'] ? 'Balanced' : 'OUT OF BALANCE' }}</span></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance (debit +)</th></tr></thead><tbody>
            @foreach ($trial['rows'] as $r)<tr><td>{{ $r['account'] }}</td><td class="num">{{ Money::format($r['debit']) }}</td><td class="num">{{ Money::format($r['credit']) }}</td><td class="num">{{ Money::format($r['balance']) }}</td></tr>@endforeach
            <tr><th>Total</th><th class="num">{{ Money::format($trial['total_debit']) }}</th><th class="num">{{ Money::format($trial['total_credit']) }}</th><th></th></tr>
        </tbody></table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Supplier payables by age</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>Lot</th><th>Supplier</th><th class="num">0–30 days</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">Over 90</th><th class="num">Outstanding</th></tr></thead><tbody>
            @forelse ($supplierAging['rows'] as $r)<tr><td>{{ $r['lot'] }}</td><td>#{{ $r['supplier_id'] }}</td><td class="num">{{ Money::format($r['current']) }}</td><td class="num">{{ Money::format($r['d31_60']) }}</td><td class="num">{{ Money::format($r['d61_90']) }}</td><td class="num">{{ Money::format($r['over_90']) }}</td><td class="num">{{ Money::format($r['outstanding']) }}</td></tr>
            @empty<tr><td colspan="7" class="muted">Nothing is owed to suppliers for accepted goods.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Customer receivables by days past due</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>Invoice</th><th>Client</th><th>Due</th><th class="num">Days past due</th><th class="num">Outstanding</th></tr></thead><tbody>
            @forelse ($receivableAging['rows'] as $r)<tr><td>{{ $r['invoice'] }}</td><td>{{ $r['client'] }}</td><td>{{ $r['due_on'] }}</td><td class="num">{{ $r['days_past_due'] }}</td><td class="num">{{ Money::format($r['outstanding']) }}</td></tr>
            @empty<tr><td colspan="5" class="muted">No open invoices.</td></tr>@endforelse
            @if ($receivableAging['rows'])<tr><th colspan="4">Total</th><th class="num">{{ Money::format($receivableAging['total']) }}</th></tr>@endif
        </tbody></table></div>
        <div class="panel-body muted">Invoice balances are the customer's contract position; the ledger's receivables account is a separate figure and is not forced to agree with it here.</div>
    </section>
</x-layout>