@props(['icon' => 'folder', 'message'])
<div class="empty">
    <x-icon :name="$icon" />
    <p>{{ $message }}</p>
    {{ $slot }}
</div>
