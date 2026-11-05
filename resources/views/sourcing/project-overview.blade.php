@use('App\Support\Money')
@php($cur = config('finance.currency'))
@php($m = fn(int $v): string => Money::format($v, $cur))
<x-layout :title="$o['project']->name">
    <x-page-header :title="$o['project']->name . ' — financial overview'" :crumbs="[['Project Margins', route('sourcing.projects')], [$o['project']->name, null]]">
        <x-slot:meta>Client {{ $o['project']->client->name }}. Money from the client is shown separately from cost and
            from profit: receiving cash is never profit. Figures marked estimated or provisional can still
            change.</x-slot:meta>
        <x-slot:badges><span class="badge {{ $o['margin_status'] === 'final' ? 'badge-paid' : 'badge-partial' }}">Margin
                {{ $o['margin_status'] === 'none' ? 'not applicable' : $o['margin_status'] }}</span></x-slot:badges>
    </x-page-header>

    <h2 class="metric-group-label">Client</h2>
    <div class="metrics-auto">
        <div class="metric">
            <div class="label">Agreed client amount (products)</div>
            <div class="value">{{ $m($o['agreed']) }}</div>
        </div>
        <div class="metric">
            <div class="label">Total invoiced</div>
            <div class="value">{{ $m($o['invoiced']) }}</div>
        </div>
        <div class="metric">
            <div class="label">Client payments received</div>
            <div class="value">{{ $m($o['received']) }}</div>
        </div>
        <div class="metric @if ($o['client_outstanding'] > 0) is-danger @endif">
            <div class="label">Client outstanding</div>
            <div class="value">{{ $m($o['client_outstanding']) }}</div>
        </div>
    </div>

    <h2 class="metric-group-label">Supplier and acquisition cost</h2>
    <div class="metrics-auto">
        <div class="metric">
            <div class="label">Supplier advances paid</div>
            <div class="value">{{ $m($o['supplier_advances_paid']) }}</div>
        </div>
        <div class="metric">
            <div class="label">Actual supplier purchase cost</div>
            <div class="value">{{ $m($o['purchase_cost']) }}</div>
        </div>
        <div class="metric">
            <div class="label">Transport &amp; handling cost</div>
            <div class="value">{{ $m($o['handling_cost']) }}</div>
        </div>
        <div class="metric">
            <div class="label">Total acquisition cost (actual)</div>
            <div class="value">{{ $m($o['acquisition_cost']) }}</div>
        </div>
        <div class="metric">
            <div class="label">Still estimated (not yet incurred)</div>
            <div class="value">{{ $m($o['open_estimates']) }}</div>
        </div>
        <div class="metric @if ($o['supplier_outstanding'] > 0) is-danger @endif">
            <div class="label">Supplier outstanding</div>
            <div class="value">{{ $m($o['supplier_outstanding']) }}</div>
        </div>
    </div>

    <h2 class="metric-group-label">Results, kept apart</h2>
    <div class="metrics-auto">
        <div class="metric">
            <div class="label">Estimated margin</div>
            <div class="value">{{ $m($o['estimated_margin']) }}</div>
            <div class="secondary">Agreed amount − estimated cost</div>
        </div>
        <div class="metric">
            <div class="label">Actual sourcing margin ({{ $o['margin_status'] === 'final' ? 'final' : 'provisional' }})
            </div>
            <div class="value">{{ $m($o['actual_margin']) }}</div>
            <div class="secondary">Agreed amount − actual acquisition cost</div>
        </div>
        <div class="metric">
            <div class="label">Recognised profit</div>
            <div class="value">{{ $m($o['recognised_margin']) }}</div>
            <div class="secondary">Only after the approved recognition event</div>
        </div>
        <div class="metric">
            <div class="label">Project cash surplus</div>
            <div class="value">{{ $m($o['cash_surplus']) }}</div>
            <div class="secondary">Client cash received − cash paid out (not profit)</div>
        </div>
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>Sourcing lots</h2>
        </div>
        <div class="table-wrap">
            <table class="table table-min-640">
                <thead>
                    <tr>
                        <th>Lot</th>
                        <th>Operational</th>
                        <th>Goods</th>
                        <th class="num">Landed cost</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lots as $lot)
                    @php($lf = \App\Services\LotFinancials::for($lot))
                    <tr>
                        <td><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a> <span
                                class="muted">{{ $lot->title }}</span></td>
                        <td><x-status :value="$lot->status" /></td>
                        <td>{{ \App\Models\SourcingLot::PROCUREMENT[$lot->procurement_status] ?? $lot->procurement_status }}
                        </td>
                        <td class="num">{{ $m($lf['landed']) }}</td>
                        <td class="num"><a class="btn btn-sm"
                                href="{{ route('sourcing.lots.show', [$lot, 'tab' => 'receiving']) }}">Receive goods</a>
                            <a class="btn btn-sm"
                                href="{{ route('sourcing.lots.show', [$lot, 'tab' => 'payments']) }}">Payments</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="muted">No sourcing lot is linked to this project yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layout>