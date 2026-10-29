@use('App\Support\Money')
@use('App\Http\Controllers\OpeningController')
<x-layout title="Financial Reports">
    <x-page-header title="Financial Reports">
        <x-slot:meta>Balances come straight from the journal as credit minus debit: funds, liabilities and equity are positive; cash and receivables (debit balances) show negative.</x-slot:meta>
        <x-slot:actions><a class="btn" href="{{ route('finance.reports', ['format' => 'csv']) }}"><x-icon name="download" class="icon-sm" /> Export CSV</a></x-slot:actions>
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>Revenue, refunds, advances and net profit by closed month</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Month</th><th class="num">Collected</th><th class="num">New advances</th><th class="num">Advances recognised</th><th class="num">Refunds</th><th class="num">Revenue</th><th class="num">Expenses</th><th class="num">Net profit</th></tr></thead>
            <tbody>
            @forelse ($periods as $p)
                <tr><td>{{ $p['period']->month }}</td><td class="num">{{ Money::format($p['rev']['collected']) }}</td><td class="num">{{ Money::format($p['rev']['advances']) }}</td>
                    <td class="num">{{ Money::format($p['rev']['recognised']) }}</td><td class="num">{{ Money::format($p['rev']['refund_revenue'] + $p['rev']['refund_advance']) }}</td>
                    <td class="num">{{ Money::format($p['period']->income) }}</td><td class="num">{{ Money::format($p['period']->expenses) }}</td><td class="num strong">{{ Money::format($p['period']->net_profit) }}</td></tr>
            @empty
                <tr><td colspan="8" class="muted">No closed periods.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <div class="grid grid-2">
        <section class="panel">
            <div class="panel-head"><h2>Closing balances (credit − debit)</h2></div>
            <div class="table-wrap"><table class="table"><tbody>
                @foreach ($rows as [$label, $amount])
                    <tr><td>{{ $label }}</td><td class="num">{{ Money::format($amount) }}</td></tr>
                @endforeach
                <tr><td class="strong">Ledger check (debits = credits)</td><td class="num strong">{{ $trialDebit === $trialCredit ? 'Balanced' : 'OUT OF BALANCE' }}</td></tr>
            </tbody></table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Payments out</h2></div>
            <div class="table-wrap"><table class="table"><tbody>
                <tr><td>Partner profit payouts paid</td><td class="num">{{ Money::format($payoutsPaid) }}</td></tr>
                <tr><td>Partner salary paid (separate from profit)</td><td class="num">{{ Money::format($salaryPaid) }}</td></tr>
                @foreach ($exits as $e)
                    <tr><td>Exit {{ $e->partner->code }} ({{ $e->payment_status }})</td><td class="num">{{ Money::format($e->net_settlement) }}</td></tr>
                @endforeach
            </tbody></table></div>
            <div class="panel-head"><h2>Opening balances posted</h2></div>
            <div class="table-wrap"><table class="table"><tbody>
                @forelse ($opening as $kind => $total)
                    <tr><td>{{ OpeningController::KINDS[$kind][0] }}</td><td class="num">{{ Money::format((int) $total) }}</td></tr>
                @empty
                    <tr><td class="muted">None.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    </div>
</x-layout>
