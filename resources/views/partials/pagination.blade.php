@if ($paginator->hasPages())
    <div class="panel-foot actions" style="justify-content:space-between">
        <span class="muted">Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
        <span class="actions">
            @if ($paginator->previousPageUrl())
                <a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}">Previous</a>
            @endif
            @if ($paginator->nextPageUrl())
                <a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}">Next</a>
            @endif
        </span>
    </div>
@endif
