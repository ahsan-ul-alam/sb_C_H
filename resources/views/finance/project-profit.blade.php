@use('App\Support\Money')
<x-layout title="Project Profit">
    <x-page-header title="Project Profit & Company Reconciliation">
        <x-slot:meta>{{ $report['from'] }} to {{ $report['to'] }}, from the journal. Every figure follows its journal entry to the project its source belongs to; what no source ties to a project stays at company level, and shared overhead is never spread over projects (no approved allocation policy exists).</x-slot:meta>
    </x-page-header>
    <section class="panel"><form method="GET" class="panel-body" style="display:flex; gap:.6rem; flex-wrap:wrap; align-items:end"><div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="{{ $report['from'] }}"></div><div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="{{ $report['to'] }}"></div><button class="btn btn-primary">Show</button></form></section>
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760"><thead><tr><th>Project</th><th>Acts as</th><th class="num">Budget</th><th class="num">Earned revenue</th><th class="num">COGS</th><th class="num">Gross profit</th><th class="num">Inventory losses</th><th class="num">Other inventory results</th><th class="num">Direct expenses</th><th class="num">Contribution</th></tr></thead><tbody>
            @forelse ($report['rows'] as $r)<tr><td>{{ $r['client'] }} / {{ $r['project'] }}</td><td>{{ $r['arrangement'] ?? 'classification required' }}</td><td class="num">{{ $r['budget'] === null ? 'not configured' : Money::format($r['budget']) }}</td><td class="num">{{ Money::format($r['revenue']) }}</td><td class="num">{{ Money::format($r['cogs']) }}</td><td class="num">{{ Money::format($r['gross_profit']) }}</td><td class="num">{{ Money::format($r['inventory_losses']) }}</td><td class="num">{{ Money::format($r['other_inventory_results']) }}</td><td class="num">{{ Money::format($r['direct_expenses']) }}</td><td class="num">{{ Money::format($r['contribution']) }}</td></tr>@empty<tr><td colspan="10" class="muted">No project has attributed results in this period.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Positions</h2></div>
        <div class="table-wrap"><table class="table table-min-760"><thead><tr><th>Project</th><th class="num">Cash collected</th><th class="num">Customer advances</th><th class="num">Receivable</th><th class="num">Contract asset</th><th class="num">Owned inventory</th><th class="num">Supplier advances</th><th class="num">Supplier payable</th><th class="num">Custody stock (units)</th></tr></thead><tbody>
            @foreach ($report['rows'] as $r)<tr><td>{{ $r['project'] }}</td><td class="num">{{ Money::format($r['cash_collected']) }}</td><td class="num">{{ Money::format($r['customer_advances']) }}</td><td class="num">{{ Money::format($r['receivable']) }}</td><td class="num">{{ Money::format($r['contract_asset']) }}</td><td class="num">{{ Money::format($r['owned_inventory']) }}</td><td class="num">{{ Money::format($r['supplier_advances']) }}</td><td class="num">{{ Money::format($r['supplier_payable']) }}</td><td class="num">{{ $r['custody_units'] }}</td></tr>@endforeach
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Company reconciliation</h2><span class="badge badge-{{ $report['reconciles'] ? 'paid' : 'partial' }}">{{ $report['reconciles'] ? 'Reconciles' : 'Does not reconcile' }}</span></div>
        <div class="table-wrap"><table class="table"><tbody>
            <tr><td>Sum of project contribution</td><td class="num">{{ Money::format($report['bridge']['project_contribution']) }}</td></tr>
            <tr><td>Revenue not tied to a project (legacy cash-basis income, other)</td><td class="num">{{ Money::format($report['bridge']['unattributed_revenue']) }}</td></tr>
            <tr><td>Other income</td><td class="num">{{ Money::format($report['bridge']['other_income']) }}</td></tr>
            <tr><td>Inventory results not tied to a project (owned-stock losses, write-downs, supplier-return cost)</td><td class="num">{{ Money::format($report['bridge']['unattributed_inventory_results']) }}</td></tr>
            <tr><td>Unallocated company overhead</td><td class="num">{{ Money::format($report['bridge']['unallocated_overhead']) }}</td></tr>
            <tr><th>Company operational profit (ledger)</th><th class="num">{{ Money::format($report['bridge']['company_operational_profit']) }}</th></tr>
        </tbody></table></div>
    </section>
</x-layout>