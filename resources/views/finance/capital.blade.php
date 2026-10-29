@use('App\Support\Money')
<x-layout title="Capital Ledger">
    <x-page-header title="Investment & Capital Ledger">
        <x-slot:meta>Targets come from the latest policy; only verified entries count as paid capital.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Partner</th><th class="num">Target share</th><th class="num">Paid (verified)</th><th class="num">Actual share of paid</th></tr></thead>
            <tbody>
            @php($totalPaid = max(1, $paid->sum()))
            @foreach ($partners as $p)
                <tr><td>{{ $p->code }} · {{ $p->name }}</td>
                    <td class="num">{{ isset($policy->investment_bps[$p->code]) ? number_format($policy->investment_bps[$p->code] / 100, 2).'%' : '—' }}</td>
                    <td class="num">{{ Money::format($paid[$p->id]) }}</td>
                    <td class="num">{{ number_format(100 * $paid[$p->id] / $totalPaid, 2) }}%</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>

    @can('capital.record')
    <section class="panel">
        <div class="panel-head"><h2>Record capital paid</h2></div>
        <form method="POST" action="{{ route('finance.capital.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Partner</label><select name="partner_id" required>@foreach ($partners as $p)<option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label>Amount</label><input name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
                <div class="field"><label>Paid on</label><input type="date" name="paid_on" value="{{ old('paid_on', today()->toDateString()) }}" required></div>
                <div class="field"><label>Reference</label><input name="reference" value="{{ old('reference') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Record</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Entries</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Partner</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($entries as $e)
                <tr><td>{{ $e->paid_on->format('j M Y') }}</td><td>{{ $e->partner->code }}</td><td>{{ $e->reference }}</td>
                    <td class="num">{{ Money::format($e->amount) }}</td><td><x-status :value="$e->status" /></td>
                    <td class="num">
                        @if ($e->status === 'pending')
                            @can('capital.verify')
                                @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)
                                    <form method="POST" action="{{ route('finance.capital.decide', $e) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>
                                @endforeach
                            @endcan
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="6" class="muted">No capital entries yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
