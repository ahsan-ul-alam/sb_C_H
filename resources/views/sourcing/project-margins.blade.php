@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Project Margins">
    <x-page-header title="Project Sourcing Margins">
        <x-slot:meta>Each figure answers one question and is never added to another. Margin is agreed amount minus the actual cost of bringing the products to Sellbees's room (supplier price plus evidenced transport, loading and handling). It is not cash and not recognised profit: cash surplus is shown separately, and recognised margin appears only once a lot is completed.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>By project</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Project</th><th class="num">Agreed</th><th class="num">Billed</th><th class="num">Client received</th><th class="num">Client due</th><th class="num">Actual acquisition cost</th><th class="num">Open estimates</th><th class="num">Supplier paid</th><th class="num">Supplier payable</th><th class="num">Costs unpaid</th><th class="num">Agreed margin (estimated cost)</th><th class="num">Actual margin (actual cost)</th><th class="num">Recognised margin</th><th class="num">Cash surplus</th><th>Margin is</th></tr></thead>
            <tbody>
            @forelse ($projects as $id => $p)
                <tr><td>{{ $names[$id]->name ?? ('#'.$id) }} <span class="muted">{{ $names[$id]->client->name ?? '' }}</span></td>
                    <td class="num">{{ Money::format($p['agreed']) }}</td><td class="num">{{ Money::format($p['billed']) }}</td><td class="num">{{ Money::format($p['received']) }}</td><td class="num">{{ Money::format($p['client_outstanding']) }}</td>
                    <td class="num">{{ Money::format($p['actual_cost']) }}</td><td class="num">{{ Money::format($p['estimates_open']) }}</td><td class="num">{{ Money::format($p['supplier_paid']) }}</td><td class="num">{{ Money::format($p['supplier_payable']) }}</td><td class="num">{{ Money::format($p['costs_unpaid']) }}</td>
                    <td class="num strong">{{ Money::format($p['estimated_margin']) }}</td><td class="num">{{ Money::format($p['actual_margin']) }}</td><td class="num">{{ Money::format($p['recognised_margin']) }}</td><td class="num">{{ Money::format($p['cash_surplus']) }}</td><td>{{ ucfirst($p['margin_status']) }}</td></tr>
            @empty
                <tr><td colspan="15" class="muted">No lot is allocated to a project yet, so there is no product-sourcing margin to report.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @foreach ($lots as $l)
    <section class="panel">
        <div class="panel-head"><h2>{{ $l['lot']->number }} <span class="muted">· {{ $l['lot']->title }} · {{ $l['lot']->statusLabel() }}</span></h2>
            <div class="strong">Landed cost {{ Money::format($l['figures']['landed'], $cur) }} · unallocated stock cost {{ Money::format($l['buckets']['unallocated']['cost'], $cur) }}</div></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Product</th><th class="num">Purchased</th><th class="num">Received</th><th class="num">Accepted</th><th class="num">Not usable</th><th class="num">Still to arrive</th><th class="num">Allocated</th><th>Arrived</th><th></th></tr></thead>
            <tbody>
            @foreach ($l['quantities'] as $q)
                <tr><td>{{ $q['line']->description }}</td><td class="num">{{ $q['purchased'] }}</td>
                    <td class="num">{{ $q['received'] ?? 'not recorded' }}</td><td class="num">{{ $q['accepted'] }}</td><td class="num">{{ $q['not_usable'] }}</td><td class="num">{{ $q['pending'] ?? '—' }}</td><td class="num">{{ $q['allocated'] }}</td>
                    <td>{{ $q['arrived_on']?->format('j M Y') ?? '—' }}</td>
                    <td>@can('sourcing.manage')@unless ($l['lot']->isClosed())
                        <form method="POST" action="{{ route('sourcing.lines.arrival', $q['line']) }}" style="display:flex; gap:.3rem; flex-wrap:wrap">@csrf
                            <input name="received_quantity" placeholder="Received" inputmode="decimal" required style="max-width:6rem"><input name="accepted_quantity" placeholder="Accepted" inputmode="decimal" style="max-width:6rem"><input name="acceptance_reference" placeholder="Delivery note / inspection ref" style="max-width:11rem"><input type="date" name="arrived_on" max="{{ today()->toDateString() }}" required><button class="btn btn-sm">Record arrival</button>
                        </form>@endunless @endcan</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
    @endforeach
</x-layout>
