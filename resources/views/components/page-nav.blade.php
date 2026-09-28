{{--
    Back / prev / next bar (template pagenav.php). Resolved by Navigation::pageNav() from
    config/pagenav.php; a missing prev/next is omitted, never rendered disabled.
    data-page-back: page-back.js upgrades Back to history.back() when the visitor came from here.
--}}
@props(['pageNav'])

<nav class="page-nav" aria-label="{{ __('Page') }}">
    <div class="container is-chrome">
        <div class="page-nav-inner">
            <a class="page-nav-back" href="{{ $pageNav->back->url }}" data-page-back wire:navigate>
                <i class="fa fa-angle-left" aria-hidden="true"></i>
                <span>{{ __('Back') }}<span class="page-nav-back-to"> {{ __('to :page', ['page' => $pageNav->back->label]) }}</span></span>
            </a>

            <p class="page-nav-title">{{ $pageNav->title }}</p>

            <div class="page-nav-steps">
                @if ($pageNav->prev)
                    <a class="page-nav-step" href="{{ $pageNav->prev->url }}" aria-label="{{ __('Previous: :page', ['page' => $pageNav->prev->label]) }}" wire:navigate>
                        <i class="fa fa-angle-left" aria-hidden="true"></i>
                        <span class="page-nav-step-label">{{ $pageNav->prev->label }}</span>
                    </a>
                @endif

                @if ($pageNav->next)
                    <a class="page-nav-step" href="{{ $pageNav->next->url }}" aria-label="{{ __('Next: :page', ['page' => $pageNav->next->label]) }}" wire:navigate>
                        <span class="page-nav-step-label">{{ $pageNav->next->label }}</span>
                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                    </a>
                @endif
            </div>
        </div>
    </div>
</nav>
