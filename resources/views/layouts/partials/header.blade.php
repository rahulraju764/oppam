{{--
    Site header (template header.php): skip link, sticky navbar, off-canvas drawer, page-nav bar.
    $member: ?App\Data\Profile\MemberChromeData — null renders the public (logged-out) nav.
    $pageNav: ?App\Support\Navigation\PageNav — rendered INSIDE .sticky-nav on purpose (it
    inherits the bar's sticky / hide-on-scroll behaviour). Drawer rules: template-notes.md.
--}}
@inject('nav', 'App\Support\Navigation\Navigation')

<a class="skip-link" href="#main">{{ __('Skip to main content') }}</a>

<header>
    <section class="nav-w100 sticky-nav">
        <div class="container is-chrome">
            <nav class="navbar navbar-expand-lg {{ $member ? 'navbar-member' : 'navbar-public' }}" aria-label="{{ __('Main') }}">

                <a class="navbar-brand nav-logo" href="{{ $nav->homeUrl() }}" wire:navigate>
                    <img src="{{ asset('images/logo/oppam-logo.webp') }}" class="img-fluid" alt="{{ config('oppam.site.name') }}" width="600" height="301" fetchpriority="high" decoding="async">
                </a>

                @if ($member)
                    @include('layouts.partials.header-member-mobile', ['member' => $member])
                @endif

                {{-- No data-bs-toggle: below 992px the menu is an off-canvas drawer driven by drawer.js. --}}
                <button class="navbar-toggler custom-toggler" type="button" aria-controls="navbarSupportedContent" aria-expanded="false"
                        aria-label="{{ __('Open menu') }}" data-label-open="{{ __('Open menu') }}" data-label-close="{{ __('Close menu') }}">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                {{-- No `collapse` class: that would hide the drawer outright on mobile. --}}
                <div class="navbar-collapse" id="navbarSupportedContent" data-dialog-label="{{ __('Menu') }}">

                    <div class="nav-drawer-head d-lg-none">
                        <img src="{{ asset('images/logo/oppam-logo.webp') }}" class="nav-drawer-logo" alt="{{ config('oppam.site.name') }}" width="600" height="301" fetchpriority="high" decoding="async">
                        <button type="button" class="nav-drawer-close" aria-label="{{ __('Close menu') }}">
                            <i class="fa fa-times" aria-hidden="true"></i>
                        </button>
                    </div>

                    @if ($member)
                        @php($myProfileUrl = $nav->url('member.profile.me'))
                        @if ($myProfileUrl)
                            <a href="{{ $myProfileUrl }}" class="nav-drawer-user d-lg-none" wire:navigate>
                                <img src="{{ $member->photoUrl }}" alt="" width="600" height="600" fetchpriority="high" decoding="async">
                                <span class="nav-drawer-user-info">
                                    <strong>{{ $member->name }}</strong>
                                    <span class="profile-id">{{ $member->code }}</span>
                                </span>
                                <span class="chip">{{ $member->planLabel }}</span>
                            </a>
                        @endif
                    @endif

                    <p class="nav-drawer-label d-lg-none">{{ __('Menu') }}</p>

                    <ul class="navbar-nav ms-auto menus">
                        @if ($member)
                            @include('layouts.partials.header-member-nav', ['member' => $member])
                        @else
                            @include('layouts.partials.header-public-nav')
                        @endif
                    </ul>
                </div>
            </nav>
        </div>

        @if ($pageNav)
            <x-page-nav :page-nav="$pageNav" />
        @endif

        {{-- Drawer backdrop. drawer.js portals it (and the drawer) to <body> below 992px. --}}
        <div class="nav-backdrop d-lg-none" hidden></div>
    </section>
</header>
