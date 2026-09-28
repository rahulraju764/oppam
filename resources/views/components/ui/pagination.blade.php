{{--
    Pagination bar (template pagination.php) for a Laravel paginator. Place it in the COLUMN,
    after the .dashboard-content card — never inside it. Also the app's default pagination view
    (AppServiceProvider), so $paginator->links() renders this markup.

    paginator  Illuminate\Contracts\Pagination\LengthAwarePaginator
    label      aria-label that tells two pagers on one page apart. (Not __('Pagination'): that
               key resolves to Laravel's pagination.php language FILE, an array.)
--}}
@props(['paginator', 'label' => null])

@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        // First, last, and a window of one around the current page; gaps become an ellipsis.
        $pages = collect([1, $current - 1, $current, $current + 1, $last])
            ->filter(fn (int $page): bool => $page >= 1 && $page <= $last)
            ->unique()
            ->sort()
            ->values();
    @endphp

    <nav class="pagination-bar" aria-label="{{ $label ?? __('Results pages') }}">
        @if ($paginator->onFirstPage())
            <span class="page-link is-disabled" aria-disabled="true" aria-label="{{ __('Previous page') }}">
                <i class="fa fa-angle-left" aria-hidden="true"></i>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="page-link" rel="prev" aria-label="{{ __('Previous page') }}" wire:navigate>
                <i class="fa fa-angle-left" aria-hidden="true"></i>
            </a>
        @endif

        @foreach ($pages as $page)
            @if (! $loop->first && $page - $pages[$loop->index - 1] > 1)
                <span class="page-gap" aria-hidden="true">…</span>
            @endif

            @if ($page === $current)
                <span class="page-link is-current" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="page-link" aria-label="{{ __('Page :page', ['page' => $page]) }}" wire:navigate>{{ $page }}</a>
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="page-link" rel="next" aria-label="{{ __('Next page') }}" wire:navigate>
                <i class="fa fa-angle-right" aria-hidden="true"></i>
            </a>
        @else
            <span class="page-link is-disabled" aria-disabled="true" aria-label="{{ __('Next page') }}">
                <i class="fa fa-angle-right" aria-hidden="true"></i>
            </span>
        @endif
    </nav>
@endif
