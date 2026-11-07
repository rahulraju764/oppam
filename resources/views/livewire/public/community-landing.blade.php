{{--
    Community landing page (M12 SEO, A08), e.g. /nair-matrimony. The about page's frame (.about-section,
    .container.is-chrome, .about-content). The introduction is A08 rich text, sanitised on save
    (HtmlSanitizer), so it is printed raw (CLAUDE.md rule 9). No profiles are shown.
--}}
<div>
    <section class="about-section">
        <div class="container is-chrome">
            <div class="row align-items-center g-4">
                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-image">
                        <img src="{{ asset('images/home/oppam-proposal-01-800-700.webp') }}" alt="" class="img-fluid" width="800" height="700" fetchpriority="high" decoding="async">
                    </div>
                </div>

                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-content">
                        <p class="eyebrow">{{ __('Oppam Matrimony') }}</p>
                        <h1>{{ $page->heading }}</h1>
                        <div class="community-intro">{!! $page->intro !!}</div>

                        @if ($members !== null)
                            <p><strong>{{ __(':count+ verified members from this community', ['count' => number_format($members)]) }}</strong></p>
                        @endif

                        <div class="d-flex flex-wrap gap-3 mt-3">
                            @if ($signedIn)
                                <x-ui.button :href="route('member.search')" wire:navigate>{{ __('Search profiles') }}</x-ui.button>
                            @else
                                <x-ui.button :href="route('register')" wire:navigate>{{ __('Register free') }}</x-ui.button>
                                <x-ui.button :href="route('login')" variant="outline" wire:navigate>{{ __('Log in') }}</x-ui.button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
