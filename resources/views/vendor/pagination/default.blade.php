@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Sayfalama">
        <span class="pagination__summary">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / {{ $paginator->total() }}
        </span>

        @if ($paginator->onFirstPage())
            <span class="pagination__link" aria-disabled="true">‹</span>
        @else
            <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev"
               aria-label="Önceki sayfa">‹</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination__link" aria-disabled="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination__link" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pagination__link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next"
               aria-label="Sonraki sayfa">›</a>
        @else
            <span class="pagination__link" aria-disabled="true">›</span>
        @endif
    </nav>
@endif
