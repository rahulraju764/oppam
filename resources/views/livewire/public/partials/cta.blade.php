{{--
    Closing call-to-action band (.stories-cta) shared by /about and /success-stories. It
    branches on who is looking: a signed-in member gets their own next step, a visitor gets
    Register. The button is omitted while its route is not built (register arrives in P1.1).

    memberTitle, memberText, memberRoute, memberLabel · guestTitle, guestText · title (optional,
    shown for both)
--}}
@inject('nav', 'App\Support\Navigation\Navigation')

@php
    $isMember = $nav->isMember();
    $ctaUrl = $isMember ? $nav->url($memberRoute) : $nav->url('register');
@endphp

<section class="stories-cta">
    <div class="container is-chrome">
        <div class="stories-cta-inner">
            @isset($title)
                <h2>{{ $title }}</h2>
                <p>{{ $isMember ? $memberText : $guestText }}</p>
            @else
                <h2>{{ $isMember ? $memberTitle : $guestTitle }}</h2>
                <p>{{ $isMember ? $memberText : $guestText }}</p>
            @endisset

            @if ($ctaUrl)
                <a href="{{ $ctaUrl }}" class="view-btn" wire:navigate>
                    {{ $isMember ? $memberLabel : __('Register Free') }} <i class="fa fa-arrow-right" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </div>
</section>
