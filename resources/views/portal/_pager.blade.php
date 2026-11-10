@if ($paginator->hasPages() || $paginator->total() > 0)
<div class="pt-pager" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <span class="muted" style="font-size:.86rem">Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}</span>
    @if ($paginator->hasPages())
    <nav style="display:flex;gap:.5rem" aria-label="Pages">
        @if ($paginator->onFirstPage())<span class="pt-btn pt-btn-sm" aria-disabled="true">Previous</span>@else<a class="pt-btn pt-btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
        <span class="muted" style="align-self:center;font-size:.86rem">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())<a class="pt-btn pt-btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@else<span class="pt-btn pt-btn-sm" aria-disabled="true">Next</span>@endif
    </nav>
    @endif
</div>
@endif
