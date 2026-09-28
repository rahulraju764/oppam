{{--
    Shared error page (template 404.php design) for 403, 404, 419, 429, 500, 503. Never indexed:
    an error page must stay out of search even after launch. Uses the public chrome; a signed-in
    member keeps their own navigation once member chrome resolves from the session (P1.1).
    The real HTTP status comes from Laravel's exception handler — never a "soft" 200.

    code, title, lead · showLinks (the "Or try one of these" list — not on 500/503, where the
    rest of the site may be down too)
--}}
@inject('nav', 'App\Support\Navigation\Navigation')

@php
    // 500/503: the database, cache or session may be what failed — touch none of them.
    $degraded = ! ($showLinks ?? true);
    $homeUrl = $degraded ? route('home') : $nav->homeUrl();
    $links = $degraded ? [] : $nav->links($nav->isMember()
        ? [['member.profiles', 'Browse all profiles'], ['member.matches', 'My matches'], ['member.search', 'Search profiles'], ['member.profile.me', 'My profile'], ['plans', 'Membership plans'], ['contact', 'Contact us']]
        : [['register', 'Register free'], ['login', 'Login'], ['about', 'About us'], ['plans', 'Membership plans'], ['success-stories', 'Success stories'], ['contact', 'Contact us']]);
@endphp

<x-layouts::public :seo="\App\Data\Content\SeoData::private($title.' | '.config('oppam.site.name'))">
    <section class="error-section">
        <div class="container is-readable">
            <div class="error-inner">
                {{-- The number is decoration; the heading says it in words. --}}
                <p class="error-code" aria-hidden="true">{{ $code }}</p>
                <h1 class="error-title">{{ $title }}</h1>
                <p class="error-lead">{{ $lead }}</p>

                <a href="{{ $homeUrl }}" class="view-btn error-btn">
                    <i class="fa fa-home" aria-hidden="true"></i> {{ __('Back to home') }}
                </a>

                @if ($links !== [])
                    <div class="error-links">
                        <h2 class="error-links-head">{{ __('Or try one of these') }}</h2>
                        <ul class="error-link-list">
                            @foreach ($links as $link)
                                <li><a href="{{ $link->url }}">{{ $link->label }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </section>
</x-layouts::public>
