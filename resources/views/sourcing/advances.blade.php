@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Supplier Advances">
    <x-page-header title="Supplier Advances & Product Cost">
        <x-slot:meta>Standard workflow: the supplier is paid in full BEFORE the goods arrive. That payment is an asset (supplier advance), not an expense. When goods are received and accepted, the advance for those goods becomes product acquisition cost with no cash movement. Cost reaches profit only when it is recognised, and the Board has not yet approved when that happens unless a trigger is shown below.</x-slot:meta>
    </x-page-header>

    <section class="panel"><div class="panel-body">
        <strong>Sourcing basis: {{ $basis === 'accrual' && $from ? 'accrual from '.$from : 'cash (every payment is an expense when paid, as before)' }}</strong>
        <p class="muted" style="margin-top:.4rem">Cost recognition trigger: {{ $trigger ?? 'not approved: releasing cost into profit is switched off' }}.</p>
    </div></section>

    @forelse ($rows as $r)
    <section class="panel">
        <div class="panel-head"><h2>{{ $r['lot']->number }} <span class="muted">· {{ $r['lot']->title }} · operational: {{ $r['lot']->statusLabel() }}</span></h2>
            <div class="strong">Cost held {{ Money::format($r['held'], $cur) }} · not yet recognised {{ Money::format($r['unreleased'], $cur) }}</div></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Supplier</th><th class="num">Purchase value</th><th class="num">Advance paid</th><th class="num">Accepted goods value</th><th class="num">Settled into cost</th><th class="num">Advance still held (asset)</th><th></th></tr></thead>
            <tbody>
            @foreach ($r['suppliers'] as $supplierId => $s)
                <tr><td>{{ $s['supplier']->name }}</td><td class="num">{{ Money::format($s['purchase']) }}</td><td class="num">{{ Money::format($s['paid']) }}</td><td class="num">{{ Money::format($s['accepted']) }}</td><td class="num">{{ Money::format($s['settled']) }}</td><td class="num strong">{{ Money::format($s['held']) }}</td>
                    <td>@can('sourcing.manage')<form method="POST" action="{{ route('sourcing.advances.propose', $r['lot']) }}">@csrf<input type="hidden" name="supplier_id" value="{{ $supplierId }}"><button class="btn btn-sm">Propose settlement</button></form>@endcan</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @if ($r['settlements']->isNotEmpty())
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Settlement</th><th>Supplier</th><th>Dated</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($r['settlements'] as $s)
                <tr><td>#{{ $s->id }}</td><td>{{ $s->supplier->name }}</td><td>{{ $s->settled_on->format('j M Y') }}</td><td class="num">{{ Money::format($s->amount) }}</td><td><x-status :value="$s->status" /></td>
                    <td class="num">@can('sourcing.advance.approve')
                        @if ($s->status === 'pending')@foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('sourcing.advances.decide', $s) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
                        @elseif ($s->status === 'verified' && $s->created_by !== auth()->id())<details><summary class="btn btn-sm">Reverse</summary><form method="POST" action="{{ route('sourcing.advances.reverse', $s) }}" style="display:grid; gap:.4rem; margin-top:.4rem">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-sm btn-danger">Reverse</button></form></details>@endif
                    @endcan</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
        <div class="panel-body">
            <strong>Release product cost into profit</strong>
            @if ($r['obstacles'])<p class="muted">Not possible yet: {{ $r['obstacles'][0] }}</p>@else
                @can('sourcing.manage')<form method="POST" action="{{ route('sourcing.cost-recognitions.propose', $r['lot']) }}" style="display:flex; gap:.5rem; margin-top:.4rem">@csrf<input type="date" name="recognised_on" max="{{ today()->toDateString() }}" required><button class="btn btn-sm">Propose release of {{ Money::format($r['unreleased'], $cur) }}</button></form>@endcan
            @endif
            @foreach ($r['recognitions'] as $c)
                <p style="margin-top:.4rem">Release #{{ $c->id }}: {{ Money::format($c->amount, $cur) }} on {{ $c->recognised_on->format('j M Y') }} <x-status :value="$c->status" />
                @can('sourcing.advance.approve')
                    @if ($c->status === 'pending')@foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('sourcing.cost-recognitions.decide', $c) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
                    @elseif ($c->status === 'verified' && $c->created_by !== auth()->id())<form method="POST" action="{{ route('sourcing.cost-recognitions.reverse', $c) }}" style="display:inline">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-sm btn-danger">Reverse</button></form>@endif
                @endcan</p>
            @endforeach
        </div>
    </section>
    @empty
        <section class="panel"><div class="panel-body muted">No lot has a supplier advance yet. Under the cash basis supplier payments are expenses and do not appear here.</div></section>
    @endforelse
</x-layout>
