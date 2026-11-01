@if ($paginator->hasPages())
  <nav>
    @if ($paginator->onFirstPage())<span class="ayuda">← Anteriores</span>@else<a href="{{ $paginator->previousPageUrl() }}">← Anteriores</a>@endif
    <span class="ayuda">Página {{ $paginator->currentPage() }}</span>
    @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">Siguientes →</a>@else<span class="ayuda">Siguientes →</span>@endif
  </nav>
@endif
