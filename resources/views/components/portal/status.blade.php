@props(['kind', 'value'])
@php
    $v = (string) $value;
    $tone = match ($kind) {
        'order' => match (true) {
            $v === 'Delivered' => 'ok',
            $v === 'Dispatched' => 'info',
            in_array($v, ['Confirmed', 'Packed'], true) => 'gold',
            in_array($v, ['Failed', 'Returned'], true) => 'bad',
            str_starts_with($v, 'Partially') => 'warn',
            default => 'plain',
        },
        'invoice' => match ($v) { 'Paid' => 'ok', 'Partially paid', 'Partial' => 'warn', 'Overdue', 'Unpaid' => 'bad', default => 'plain' },
        'collection' => match ($v) { 'Collected' => 'ok', 'Partly collected' => 'warn', 'Awaiting collection' => 'gold', default => 'plain' },
        'project' => match ($v) { 'Active' => 'ok', 'Completed' => 'info', 'Cancelled' => 'bad', default => 'plain' },
        'stage' => match ($v) { 'Available', 'Delivered' => 'ok', 'Reserved' => 'gold', 'Dispatched', 'Sourcing in progress' => 'info', 'Returned' => 'warn', 'Damaged' => 'bad', default => 'plain' },
        'lot' =>match ($v) { 'Completed' => 'ok', 'Sourcing in progress' => 'info', 'Approved' => 'gold', default => 'plain' },
        default => 'plain',
    };
@endphp
<span class="pt-chip {{ $tone }}">{{ $v }}</span>
