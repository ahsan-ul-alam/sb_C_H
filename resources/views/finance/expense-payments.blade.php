@use('App\Support\Money')
<x-layout title="Expense Payments">
    <x-page-header title="Expense Payment History">
        <x-slot:meta>Every payment made against an unpaid (payable) expense, including voided ones. Still owed on verified expenses: {{ Money::format($owed, config('finance.currency')) }}.</x-slot:meta>
    </x-page-header>
    <form method="GET" class="toolbar">
        <div class="field"><label>Account</label><select name="account"><option value="">All</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) request('account') === $a->id)>{{ $a->name }}</option>@endforeach</select></div>
        <div class="field"><label>State</label><select name="state"><option value="">All</option><option value="live" @selected(request('state') === 'live')>Live</option><option value="voided" @selected(request('state') === 'voided')>Voided</option></select></div>
        <div class="field"><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div>
        <div class="field"><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div>
        <button class="btn">Filter</button>
    </form>
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Paid on</th><th>Expense</th><th>Account</th><th>Reference</th><th class="num">Amount</th><th>State</th></tr></thead>
            <tbody>
            @forelse ($payments as $p)
                <tr><td>{{ $p->paid_on->format('j M Y') }}</td><td>{{ $p->expense->description }}<span class="secondary">{{ collect([$p->expense->vendor, $p->expense->project?->name])->filter()->implode(' · ') }}</span></td>
                    <td class="muted">{{ $p->account?->name ?? 'Unassigned' }}</td><td>{{ $p->reference }}</td><td class="num">{{ Money::format($p->amount) }}</td>
                    <td>@if ($p->isVoided())<x-status value="voided" /><span class="secondary">{{ $p->voided_at->format('j M Y') }}</span>@else<x-status value="paid" />@endif</td></tr>
            @empty
                <tr><td colspan="6" class="muted">No payments match.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $payments])
    </section>
</x-layout>