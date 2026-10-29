@use('App\Support\Money')
@use('App\Http\Controllers\ResolutionController')
<x-layout title="Board Resolutions">
    <x-page-header title="Board Resolutions">
        <x-slot:meta>Partner salary is never automatic: Board approval, then payment from the Salary Fund ({{ Money::format($salaryFund) }} available).</x-slot:meta>
    </x-page-header>

    @can('resolution.create')
    <section class="panel">
        <div class="panel-head"><h2>New resolution</h2></div>
        <form method="POST" action="{{ route('finance.resolutions.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Type</label><select name="type">
                    @foreach (ResolutionController::TYPES as $key => $label)
                        @continue(! in_array($key, ResolutionController::typesFor(auth()->user())))
                        <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                    @endforeach</select></div>
                <div class="field"><label>Title</label><input name="title" value="{{ old('title') }}" required></div>
                <div class="field"><label>Partner (salary)</label><select name="partner_id"><option value="">—</option>
                    @foreach ($partners as $p)<option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label>Employee (increment)</label><input name="employee_name" value="{{ old('employee_name') }}"></div>
                <div class="field"><label>Amount (new salary / pay)</label><input name="amount" inputmode="decimal" value="{{ old('amount') }}"></div>
                <div class="field"><label>Previous salary (increment)</label><input name="previous_amount" inputmode="decimal" value="{{ old('previous_amount') }}"></div>
                <div class="field"><label>Month</label><input type="month" name="month" value="{{ old('month') }}"></div>
                <div class="field full"><label>Notes</label><input name="notes" value="{{ old('notes') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Submit to Board</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Type</th><th>Title</th><th>Subject</th><th class="num">Amount</th><th class="num">Increment (theoretical → approved)</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($resolutions as $r)
                <tr><td>{{ ResolutionController::TYPES[$r->type] }}</td><td>{{ $r->title }}</td>
                    <td>{{ $r->partner?->code ?? $r->employee_name ?? '—' }}</td><td class="num">{{ $r->amount ? Money::format($r->amount) : '—' }}</td>
                    <td class="num">@if ($r->type === 'employee_increment'){{ number_format($r->theoretical_bps / 100, 2) }}% (rate {{ number_format($r->rate_bps / 100, 2) }}%) → {{ $r->approved_bps !== null ? number_format($r->approved_bps / 100, 2).'% from '.$r->effective_on->format('j M Y') : 'pending' }}@else—@endif</td>
                    <td><x-status :value="$r->status" /></td>
                    <td class="num">
                        @if ($r->status === 'pending')
                            @can('resolution.decide')
                                @if ($r->type === 'employee_increment')
                                    <form method="POST" action="{{ route('finance.resolutions.decide', $r) }}" style="display:inline-flex; gap:.25rem">@csrf<input type="hidden" name="decision" value="approve"><input name="approved_bps" size="5" placeholder="bps" title="Board-approved increment in basis points (1000 = 10%)"><input type="date" name="effective_on"><input name="approval_reference" placeholder="Board ref" size="8"><button class="btn btn-sm btn-success">Approve</button></form>
                                    <form method="POST" action="{{ route('finance.resolutions.decide', $r) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="reject"><button class="btn btn-sm btn-danger">Reject</button></form>
                                @else
                                    @foreach (['approve' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)
                                        <form method="POST" action="{{ route('finance.resolutions.decide', $r) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>
                                    @endforeach
                                @endif
                            @endcan
                        @elseif ($r->status === 'approved' && $r->type === 'partner_salary')
                            @can('resolution.pay')
                                <form method="POST" action="{{ route('finance.resolutions.pay', $r) }}" data-confirm="Pay from the Salary Fund?">@csrf<button class="btn btn-sm btn-primary">Pay salary</button></form>
                            @endcan
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="7" class="muted">No resolutions.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
