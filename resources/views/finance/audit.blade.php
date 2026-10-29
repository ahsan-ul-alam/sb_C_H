<x-layout title="Audit Log">
    <x-page-header title="Audit Log" />
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Subject</th><th>Details</th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr><td class="nowrap">{{ $log->created_at->format('j M Y H:i') }}</td><td>{{ $log->user?->email ?? 'system' }}</td>
                    <td>{{ $log->action }}</td><td>{{ $log->subject }}</td><td class="muted">{{ $log->details }}</td></tr>
            @empty
                <tr><td colspan="5" class="muted">No activity yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $logs])
    </section>
</x-layout>
