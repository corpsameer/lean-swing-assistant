@if ($paginator->total() > 0)
    <nav class="pagination" aria-label="Pagination Navigation">
        <div class="pagination-summary">
            Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} results
        </div>

        @if ($paginator->hasPages())
            @php
                $current = $paginator->currentPage();
                $last = $paginator->lastPage();
                $start = max(1, $current - 2);
                $end = min($last, $current + 2);
            @endphp

            <div class="pagination-links">
                @if ($paginator->onFirstPage())
                    <span class="pagination-link disabled" aria-disabled="true">Previous</span>
                @else
                    <a class="pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
                @endif

                @if ($start > 1)
                    <a class="pagination-link" href="{{ $paginator->url(1) }}">1</a>
                    @if ($start > 2)
                        <span class="pagination-ellipsis">…</span>
                    @endif
                @endif

                @for ($page = $start; $page <= $end; $page++)
                    @if ($page === $current)
                        <span class="pagination-link active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pagination-link" href="{{ $paginator->url($page) }}">{{ $page }}</a>
                    @endif
                @endfor

                @if ($end < $last)
                    @if ($end < $last - 1)
                        <span class="pagination-ellipsis">…</span>
                    @endif
                    <a class="pagination-link" href="{{ $paginator->url($last) }}">{{ $last }}</a>
                @endif

                @if ($paginator->hasMorePages())
                    <a class="pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
                @else
                    <span class="pagination-link disabled" aria-disabled="true">Next</span>
                @endif
            </div>
        @endif
    </nav>
@endif
