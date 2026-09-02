@if ($paginator->hasPages())
  <nav class="pager" role="navigation" aria-label="Pagination">
    <div class="pager-info">
      Showing <b>{{ $paginator->firstItem() }}</b>–<b>{{ $paginator->lastItem() }}</b>
      of <b>{{ $paginator->total() }}</b>
    </div>

    <div class="pager-links">
      @if ($paginator->onFirstPage())
        <span class="pager-btn disabled" aria-disabled="true">‹ Prev</span>
      @else
        <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Prev</a>
      @endif

      @foreach ($elements as $element)
        @if (is_string($element))
          <span class="pager-gap">{{ $element }}</span>
        @endif

        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span class="pager-btn current" aria-current="page">{{ $page }}</span>
            @else
              <a class="pager-btn" href="{{ $url }}">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach

      @if ($paginator->hasMorePages())
        <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
      @else
        <span class="pager-btn disabled" aria-disabled="true">Next ›</span>
      @endif
    </div>
  </nav>
@endif
