<x-layout title="Policies">
    <x-page-header title="Policy Versions">
        <x-slot:meta>Approved versions are never edited; new rules apply only to periods on or after their effective date.</x-slot:meta>
    </x-page-header>

    @foreach ($policies as $p)
        <section class="panel">
            <div class="panel-head">
                <div><h2>{{ $p->name }} @if ($p->is_legacy)<span class="badge badge-archived">Legacy</span>@endif</h2>
                    <div class="sub">Effective {{ $p->effective_from->format('j M Y') }}</div></div>
                <div>
                    <span class="badge {{ $p->status() === 'ACTIVE' ? 'badge-paid' : 'badge-partial' }}">{{ $p->status() }}</span>
                    <span class="badge {{ $p->isProfitConfirmed() ? 'badge-paid' : 'badge-partial' }}">Profit ratio {{ $p->isProfitConfirmed() ? 'confirmed' : 'unconfirmed' }}</span>
                </div>
            </div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Partner</th><th class="num">Investment target</th><th class="num">Ownership</th><th class="num">Loss</th><th class="num">Profit fund</th></tr></thead>
                <tbody>
                @foreach ($p->investment_bps as $code => $bps)
                    <tr><td>{{ $code }}</td><td class="num">{{ number_format($bps / 100, 2) }}%</td>
                        <td class="num">{{ number_format(100 * $p->ownership_shares[$code] / array_sum($p->ownership_shares), 2) }}%</td>
                        <td class="num">{{ number_format(100 * $p->loss_shares[$code] / array_sum($p->loss_shares), 2) }}%</td>
                        <td class="num">{{ number_format($p->profit_bps[$code] / 100, 2) }}%</td></tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="panel-foot">
                @foreach ($documents[$p->id] ?? [] as $d)
                    <div><a href="{{ route('finance.documents.download', $d) }}">{{ $d->name }}</a> — {{ $d->review_status }}
                        @if ($d->review_status === 'pending') @can('policy.approve')
                            <form method="POST" action="{{ route('finance.documents.review', $d) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="approve"><button class="btn btn-sm btn-success">Approve</button></form>
                        @endcan @endif
                    </div>
                @endforeach
                @can('document.upload')
                    <form method="POST" action="{{ route('finance.documents.store') }}" enctype="multipart/form-data" class="compact-form" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-start; margin:.3rem 0">@csrf
                        <input type="hidden" name="subject_type" value="policy"><input type="hidden" name="subject_id" value="{{ $p->id }}">
                        <input name="name" placeholder="Signed policy / resolution" required><span class="upload-wrap"><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png" aria-label="Document file (PDF, JPG or PNG, up to 8 MB)"><span class="upload-note">PDF, JPG or PNG · max 8 MB</span></span><button class="btn btn-sm">Attach evidence</button>
                    </form>
                @endcan
                @if ($p->isApproved()) Signed ref: {{ $p->signed_reference }}. @endif
                @if ($p->isProfitConfirmed()) Profit-ratio ref: {{ $p->profit_signed_reference }}. @endif
                @can('policy.approve')
                    @if (! $p->isApproved())
                        <form method="POST" action="{{ route('finance.policies.approve', $p) }}" style="display:flex; gap:.5rem; margin-top:.5rem">@csrf
                            <input type="date" name="effective_from" title="Approved effective date" required>
                            <input name="signed_reference" placeholder="Signed resolution reference" required>
                            <button class="btn btn-sm btn-success">Approve policy</button>
                        </form>
                    @elseif (! $p->isProfitConfirmed())
                        <form method="POST" action="{{ route('finance.policies.confirm-profit', $p) }}" style="display:flex; gap:.5rem; margin-top:.5rem">@csrf
                            <input name="profit_signed_reference" placeholder="Signed profit-ratio confirmation reference" required>
                            <button class="btn btn-sm btn-success">Confirm profit ratio</button>
                        </form>
                    @endif
                @endcan
            </div>
        </section>
    @endforeach

    @can('policy.create')
    <section class="panel">
        <div class="panel-head"><h2>New draft policy</h2></div>
        <form method="POST" action="{{ route('finance.policies.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Name</label><input name="name" value="{{ old('name') }}" required></div>
                <div class="field"><label>Effective from</label><input type="date" name="effective_from" value="{{ old('effective_from') }}" required></div>
            </div>
            <p class="muted" style="margin-top:.75rem">Ratios in basis points (4000 = 40%); each set must total 10000. Ownership and loss are always equal shares.</p>
            <div class="form-grid">
                @foreach ($partners as $partner)
                    <div class="field"><label>{{ $partner->code }} investment</label><input type="number" name="investment[{{ $partner->code }}]" value="{{ old('investment.'.$partner->code) }}" required></div>
                @endforeach
                @foreach ($partners as $partner)
                    <div class="field"><label>{{ $partner->code }} profit fund</label><input type="number" name="profit[{{ $partner->code }}]" value="{{ old('profit.'.$partner->code) }}" required></div>
                @endforeach
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Save draft</button>
        </form>
    </section>
    @endcan
</x-layout>
