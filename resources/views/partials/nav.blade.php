@php($u = auth()->user())
<button type="button" class="nav-search" data-cmdk-open aria-haspopup="dialog" aria-label="Search the ERP">
    <x-icon name="search" /> <span>Search</span> <kbd data-cmdk-hint>Ctrl K</kbd>
</button>
@foreach (\App\Support\Navigation::visible($u) as $label => $items)
    <details class="nav-group" data-group="{{ $label }}" open>
        <summary>{{ $label }}</summary>
        @foreach ($items as [$text, $route, $perm, $icon, $patterns])
            @php($active = request()->routeIs(...$patterns))
            <a href="{{ route($route) }}" @class(['active' => $active]) @if ($active) aria-current="page" @endif>
                <x-icon :name="$icon" /> <span>{{ $text }}</span>
            </a>
        @endforeach
    </details>
@endforeach
