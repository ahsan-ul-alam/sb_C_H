@use('App\Support\Money')
@php
    $tabs = ['overview' => 'Overview', 'lines' => 'Lines & costs', 'pricing' => 'Costing & pricing', 'billing' => 'Client billing', 'payments' => 'Payments & settlement', 'emergency' => 'Emergency', 'activity' => 'Activity'];
    $nextLabel = ['draft' => 'Move to costing', 'costing' => 'Submit pricing for approval', 'approved' => 'Start work', 'in_progress' => 'Mark completed'][$lot->status] ?? null;
    $isLoss = $f['profit'] < 0;
@endphp
<x-layout :title="$lot->number">
    <x-page-header :title="$lot->number.' — '.$lot->title" :crumbs="[['Sourcing Lots', route('sourcing.lots.index')], [$lot->number, null]]">
        <x-slot:badges>
            <x-status :value="$lot->status" />
            @if ($isLoss)<span class="badge badge-unpaid">Loss {{ Money::format(-$f['profit']) }}</span>@endif
            @if ($f['fund_pending'] > 0)<span class="badge badge-partial">Fund request pending</span>@endif
        </x-slot:badges>
        <x-slot:meta>Lifecycle: Draft → Costing → Priced → Approved → In progress → Completed → Financially settled</x-slot:meta>
        <x-slot:actions>
            @can('sourcing.manage')
                @if ($nextLabel)
                    <form method="POST" action="{{ route('sourcing.lots.advance', $lot) }}" style="display:inline">@csrf<button class="btn btn-primary">{{ $nextLabel }}</button></form>
                @endif
            @endcan
            @if ($lot->status === 'priced')
                @can('sourcing.price.approve')
                    @if ($lot->priced_by !== auth()->id())
                        <form method="POST" action="{{ route('sourcing.lots.approve', $lot) }}" style="display:inline">@csrf<button class="btn btn-primary">Approve pricing</button></form>
                    @endif
                @endcan
            @endif
            @if ($lot->status === 'completed')
                @can('sourcing.settle')<form method="POST" action="{{ route('sourcing.lots.settle', $lot) }}" style="display:inline">@csrf<button class="btn btn-primary">Settle financially</button></form>@endcan
            @endif
            @if ($lot->status === 'settled')
                @can('sourcing.settle')
                    <form method="POST" action="{{ route('sourcing.lots.reopen', $lot) }}" class="inline-form">@csrf<input name="reason" placeholder="Reason for audited adjustment" required><button class="btn">Reopen</button></form>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    <nav class="tabs" aria-label="Lot sections">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('sourcing.lots.show', [$lot, 'tab' => $key]) }}" @class(['active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @include('sourcing.lots.tabs.'.$tab)
</x-layout>
