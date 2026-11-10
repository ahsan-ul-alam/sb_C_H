@use('App\Support\Money')
<x-portal-layout title="Invoices & Payments">
    <section class="panel"><div class="panel-head"><h2>Invoices &amp; payments</h2><span class="sub">Only verified payments are counted; a cancelled payment is not shown.</span></div>
        <div class="table-wrap"><table class="table table-min-760"><thead><tr><th>Invoice</th><th>Date</th><th>Project</th><th class="num">Amount</th><th class="num">Paid</th><th class="num">Outstanding</th><th>Status</th><th>Payments</th><th></th></tr></thead><tbody>
        @forelse ($invoices as $i)<tr><td>{{ $i->number }}</td><td>{{ $i->issued_on->format('j M Y') }}</td><td>{{ $i->project->name }}</td><td class="num">{{ Money::format($i->total) }}</td><td class="num">{{ Money::format($i->amount_paid) }}</td><td class="num">{{ Money::format($i->balance()) }}</td><td>{{ $i->status->label() }}</td>
            <td>@forelse ($i->payments as $p){{ $p->paid_on->format('j M Y') }} · {{ Money::format($p->amount) }}<br>@empty <span class="muted">none yet</span>@endforelse</td>
            <td><a class="btn btn-sm" href="{{ route('portal.invoices.pdf', $i->id) }}" target="_blank" rel="noopener">View PDF</a> <a class="btn btn-sm" href="{{ route('portal.invoices.pdf', [$i->id, 'download' => 1]) }}">Download</a></td></tr>
        @empty<tr><td colspan="9" class="muted">No invoices yet.</td></tr>@endforelse</tbody></table></div>
        <div class="panel-foot">{{ $invoices->links() }}</div></section>
</x-portal-layout>