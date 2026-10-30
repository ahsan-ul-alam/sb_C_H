<section class="panel">
    <div class="panel-head"><h2>Activity / audit history</h2></div>
    <div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead>
        <tbody>
        @forelse ($activity as $a)
            <tr><td class="nowrap">{{ $a->created_at->format('j M Y H:i') }}</td><td>{{ $a->user?->name ?? 'System' }}</td><td>{{ $a->action }}</td><td>{{ $a->details }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">No activity yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
