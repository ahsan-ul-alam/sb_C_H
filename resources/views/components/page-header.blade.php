@props(['title', 'crumbs' => []])
{{-- $crumbs: [[label, url|null], ...] --}}
<div class="page-header">
    <div style="min-width:0">
        @if ($crumbs)
            <nav class="breadcrumbs" aria-label="Breadcrumb">
                @foreach ($crumbs as [$label, $url])
                    @if ($url)
                        <a href="{{ $url }}">{{ $label }}</a>
                    @else
                        <span aria-current="page">{{ $label }}</span>
                    @endif
                    @unless ($loop->last)
                        <x-icon name="chevron-right" />
                    @endunless
                @endforeach
            </nav>
        @endif
        <div class="title-row">
            <h1>{{ $title }}</h1>
            {{ $badges ?? '' }}
        </div>
        @isset($meta)
            <div class="meta">{{ $meta }}</div>
        @endisset
    </div>
    @isset($actions)
        <div class="actions">{{ $actions }}</div>
    @endisset
</div>
