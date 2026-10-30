@use('App\Support\Money')
<x-layout title="Financial Position">
    <x-page-header title="Company Financial Position">
        <x-slot:meta>Management statement from the double-entry journal. Income is recognised when clients pay and expenses when paid and verified, so this is NOT a full accrual balance sheet.</x-slot:meta>
    </x-page-header>

    @unless ($p['balanced'])
        <div class="flash flash-error" role="alert"><x-icon name="alert" /> <div>Assets do not equal liabilities + equity. The journal must be investigated before relying on this page.</div></div>
    @endunless
    <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div><strong>Disclosure.</strong> Not recognised in these balances: client invoices not yet paid, supplier bills not yet paid, and unpaid lot costs (shown separately below). Office equipment is carried at cost without depreciation. Assets and liabilities appear only if they were recorded in the ledger.</div></div>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Cash &amp; bank (ledger)</div><div class="value">{{ Money::format($p['cash']) }}</div></div>
        <div class="metric"><div class="label">Recorded assets (incl. cash)</div><div class="value">{{ Money::format($p['assets']) }}</div></div>
        <div class="metric"><div class="label">Recorded liabilities</div><div class="value">{{ Money::format($p['liabilities']) }}</div></div>
        <div class="metric"><div class="label">Equity, funds &amp; accumulated results</div><div class="value">{{ Money::format($p['equity']) }}</div></div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:1rem">
        @foreach (['assets' => 'Recognised assets', 'liabilities' => 'Recorded liabilities (incl. customer advances and partner entitlements)', 'capital' => 'Capital contributions (net of loss charges) & opening equity', 'funds' => 'Funds & reserves', 'result' => 'Accumulated results not yet allocated, and other'] as $key => $title)
            <section class="panel">
                <div class="panel-head"><h2>{{ $title }}</h2></div>
                <div class="table-wrap"><table class="table">
                    @forelse ($p['groups'][$key] as $line)
                        <tr><td>{{ $line['label'] }}</td><td class="num">{{ Money::format($line['amount']) }}</td></tr>
                    @empty
                        <tr><td class="muted">Nothing posted.</td><td></td></tr>
                    @endforelse
                    <tr><td class="strong">Total</td><td class="num strong">{{ Money::format($p[$key]) }}</td></tr>
                </table></div>
            </section>
        @endforeach
    </div>

    <section class="panel">
        <div class="panel-head"><h2>Free cash after commitments</h2><span class="sub">Each amount is subtracted once: the first block is already a ledger liability, the second is not in the ledger at all.</span></div>
        <div class="table-wrap"><table class="table">
            <tr><td>Cash &amp; bank (ledger)</td><td class="num">{{ Money::format($p['cash']) }}</td></tr>
            <tr><td class="muted" colspan="2">Recorded liabilities (claims on cash that are in the ledger)</td></tr>
            <tr><td>&nbsp;&nbsp;Partner profit entitlement owed</td><td class="num">{{ Money::format((int) (collect($p['groups']['liabilities'])->firstWhere('label', 'Partner profit entitlement payable')['amount'] ?? 0)) }}</td></tr>
            <tr><td>&nbsp;&nbsp;Customer advances (unearned)</td><td class="num">{{ Money::format((int) (collect($p['groups']['liabilities'])->firstWhere('label', 'Customer advances (unearned)')['amount'] ?? 0)) }}</td></tr>
            <tr><td>&nbsp;&nbsp;Supplier / other liabilities</td><td class="num">{{ Money::format((int) (collect($p['groups']['liabilities'])->firstWhere('label', 'Supplier / other liabilities')['amount'] ?? 0)) }}</td></tr>
            <tr><td class="muted" colspan="2">Business commitments NOT in the ledger (cash-basis books)</td></tr>
            <tr><td>&nbsp;&nbsp;Sourcing: supplier payables {{ Money::format($p['sourcing']['suppliers']) }}, unpaid lot costs {{ Money::format($p['sourcing']['costs']) }}, payments awaiting verification {{ Money::format($p['sourcing']['pending']) }}</td><td class="num">{{ Money::format($p['sourcing']['total']) }}</td></tr>
            <tr><td class="strong">Free cash after commitments</td><td class="num strong {{ $p['free_cash'] < 0 ? 'text-danger' : '' }}">{{ Money::format($p['free_cash']) }}</td></tr>
            <tr><td class="muted">Memo: client invoices outstanding (receivable, not yet cash — not counted above)</td><td class="num muted">{{ Money::format($p['client_receivable']) }}</td></tr>
            <tr><td class="muted">Memo: the fund balances above are earmarked reserves inside cash, not liabilities, and are not subtracted</td><td class="num muted">{{ Money::format($p['funds']) }}</td></tr>
        </table></div>
        <div class="panel-foot muted">If an opening-balance liability and a sourcing payable describe the same supplier bill, it would be counted in both lines; record opening supplier balances only through their own lots to avoid that.</div>
    </section>
</x-layout>
