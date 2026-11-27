{{-- Previous / next with the page position. <x-pagination :paginator="$rows" /> --}}
@props(['paginator'])
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="btn btn-sm" aria-disabled="true">{{ __('Previous') }}</span>
        @else
            <a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>
        @endif
        <span class="muted text-sm">{{ __('Page :page of :last', ['page' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
        @if ($paginator->hasMorePages())
            <a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>
        @else
            <span class="btn btn-sm" aria-disabled="true">{{ __('Next') }}</span>
        @endif
    </nav>
@endif
