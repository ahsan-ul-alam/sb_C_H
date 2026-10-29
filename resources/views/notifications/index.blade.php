<x-layout title="Notifications">
    <x-page-header title="Notifications">
        <x-slot:meta>{{ $unread }} unread</x-slot:meta>
        <x-slot:actions>
            <a class="btn {{ $unreadOnly ? '' : 'btn-primary' }}" href="{{ route('notifications.index') }}" data-live>All</a>
            <a class="btn {{ $unreadOnly ? 'btn-primary' : '' }}" href="{{ route('notifications.index', ['filter' => 'unread']) }}" data-live>Unread</a>
            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn">Mark All as Read</button></form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <section class="panel">
        @forelse ($notifications as $n)
            @php($p = \App\Http\Controllers\NotificationController::present($n))
            <div class="notice-row {{ $p['read'] ? '' : 'is-unread' }}">
                <span class="notice-cat badge badge-plain">{{ ucfirst($p['category']) }}</span>
                <div class="notice-main">
                    <div class="strong">{{ $p['title'] }} @unless ($p['read'])<span class="badge badge-partial">Unread</span>@endunless</div>
                    <div class="muted">{{ $p['message'] }}</div>
                    <div class="muted small">{{ $p['time'] }}</div>
                </div>
                <div class="actions">
                    @if ($p['url'])<a class="btn btn-sm" href="{{ $p['url'] }}" data-read-url="{{ route('notifications.read', $p['id']) }}">Open</a>@endif
                    @unless ($p['read'])
                        <form method="POST" action="{{ route('notifications.read', $p['id']) }}">@csrf<button class="btn btn-sm btn-ghost">Mark as Read<span class="sr-only">: {{ $p['title'] }}</span></button></form>
                    @endunless
                </div>
            </div>
        @empty
            <x-empty-state icon="check-circle" message="{{ $unreadOnly ? 'You have no unread notifications.' : 'You\'re all caught up.' }}" />
        @endforelse
        @include('partials.pagination', ['paginator' => $notifications])
    </section>
</x-layout>
