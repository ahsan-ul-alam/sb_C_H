@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout :title="($account?->name ?? 'Unassigned cash').' — transactions'">
    <x-page-header :title="($account?->name ?? 'Unassigned (legacy cash)').' — transactions'" :crumbs="[['Accounts', route('finance.accounts')], [$account?->name ?? 'Unassigned', null]]">
        <x-slot:meta>Every journal line that moved money in or out of this account, oldest first within the period, shown newest first. The balance is the journal's, so it agrees with the ledger.</x-slot:meta>
    </x-page-header>
    <form method="GET" class="toolbar">
        <div class="field"><label>From</label><input type="date" name="from" value="{{ $from->toDateString() }}"></div>
        <div class="field"><label>To</label><input type="date" name="to" value="{{ $to->toDateString() }}"></div>
        <button class="btn">Apply</button>
    </form>
    <div class="metrics-auto">
        <div class="metric"><div class="label">Opening ({{ $from->format('j M Y') }})</div><div class="value">{{ Money::format($opening, $cur) }}</div></div>
        <div class="metric"><div class="label">Inflows</div><div class="value">{{ Money::format($in, $cur) }}</div></div>
        <div class="metric"><div class="label">Outflows</div><div class="value">{{ Money::format($out, $cur) }}</div></div>
        <div class="metric"><div class="label">Closing ({{ $to->format('j M Y') }})</div><div class="value">{{ Money::format($closing, $cur) }}</div></div>
        <div class="metric"><div class="label">Balance today</div><div class="value">{{ Money::format($current, $cur) }}</div></div>
    </div>
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th>Type</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr><td>{{ \Carbon\CarbonImmutable::parse($r['date'])->format('j M Y') }}</td><td class="muted">{{ $r['entry'] }}</td><td>{{ $r['memo'] }}</td><td class="muted">{{ str_replace('_', ' ', $r['type']) }}</td>
                    <td class="num">{{ $r['in'] ? Money::format($r['in']) : '' }}</td><td class="num">{{ $r['out'] ? Money::format($r['out']) : '' }}</td><td class="num strong">{{ Money::format($r['balance']) }}</td></tr>
            @empty
                <tr><td colspan="7" class="muted">No movements in this period.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>