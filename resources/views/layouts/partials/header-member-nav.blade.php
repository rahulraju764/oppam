{{--
    Member nav items — template header.php "MEMBER NAV". The Matches parent is a disclosure
    <button> (never a link); its children come from config/navigation.php so the header and the
    tab-bar sheet can't drift. Unbuilt routes are skipped.
--}}
@inject('nav', 'App\Support\Navigation\Navigation')

@php($dashboardUrl = $nav->url('member.dashboard'))
@if ($dashboardUrl)
    @php($active = $nav->isActive('member.dashboard'))
    <li class="nav-item">
        <a @class(['nav-link head-link', 'active' => $active]) href="{{ $dashboardUrl }}" wire:navigate @if ($active) aria-current="page" @endif>
            <i class="fa fa-home" aria-hidden="true"></i>
            <span>{{ __('Home') }}</span>
        </a>
    </li>
@endif

@php($matchesLinks = $nav->matchesLinks())
@if ($matchesLinks !== [])
    <li class="nav-item dropdown">
        <button type="button" @class(['nav-link head-link dropdown-toggle', 'active' => $nav->inMatchesSection()])
                id="navMatches" data-bs-toggle="dropdown" aria-controls="navMatchesMenu" aria-expanded="false">
            <i class="fa fa-user" aria-hidden="true"></i>
            <span>{{ __('Matches') }}</span>
        </button>
        <ul class="dropdown-menu nav-dropdown" id="navMatchesMenu" aria-labelledby="navMatches">
            @foreach ($matchesLinks as $link)
                <li>
                    <a @class(['dropdown-item', 'active' => $link->active]) href="{{ $link->url }}" wire:navigate @if ($link->active) aria-current="page" @endif>
                        <i class="fa {{ $link->icon }}" aria-hidden="true"></i> {{ $link->label }}
                    </a>
                </li>
            @endforeach
        </ul>
    </li>
@endif

@foreach ([
    ['member.interests', 'Interests', 'fa-address-book'],
    ['member.search', 'Search', 'fa-search'],
    ['plans', 'Membership', 'fa-diamond'],
] as [$routeName, $label, $icon])
    @php($url = $nav->url($routeName))
    @if ($url)
        @php($active = $nav->isActive($routeName))
        <li class="nav-item">
            <a @class(['nav-link head-link', 'active' => $active]) href="{{ $url }}" wire:navigate @if ($active) aria-current="page" @endif>
                <i class="fa {{ $icon }}" aria-hidden="true"></i>
                <span>{{ __($label) }}</span>
            </a>
        </li>
    @endif
@endforeach

{{-- Desktop bell (the mobile one lives in .mobile-header). Live in P3.2. --}}
@php($notificationsUrl = $nav->url('member.notifications'))
@if ($notificationsUrl)
    @php($active = $nav->isActive('member.notifications'))
    <li class="nav-item d-none d-lg-flex">
        <a href="{{ $notificationsUrl }}" @class(['nav-bell', 'is-current' => $active]) @if ($active) aria-current="page" @endif
           aria-label="{{ trans_choice('Notifications (:count unread)', $member->unreadNotifications, ['count' => $member->unreadNotifications]) }}" wire:navigate>
            <i class="fa fa-bell-o" aria-hidden="true"></i>
            @if ($member->unreadNotifications > 0)
                <span class="nav-bell-count" aria-hidden="true">{{ $member->unreadNotifications }}</span>
            @endif
        </a>
    </li>
@endif

{{-- Account menu --}}
<li class="nav-item profile-dropdown">
    <button type="button" class="profile-toggle" aria-expanded="false" aria-haspopup="true">
        <img src="{{ $member->photoUrl }}" alt="" width="600" height="600" fetchpriority="high" decoding="async">
        <span class="visually-hidden">{{ __('Open account menu') }}</span>
        <span class="profile-toggle-meta" aria-hidden="true">
            <strong>{{ $member->name }}</strong>
            <span class="member-badge">{{ $member->planLabel }}</span>
        </span>
        <i class="fa fa-angle-down" aria-hidden="true"></i>
    </button>

    <div class="profile-dropdown-content">
        <div class="profile-top">
            <img src="{{ $member->photoUrl }}" alt="" width="600" height="600" loading="lazy" decoding="async">
            <div class="profile-content">
                <h4>{{ $member->name }}</h4>
                <p class="profile-id">{{ $member->code }}</p>
                <span>{{ __(':plan Member', ['plan' => $member->planLabel]) }}</span>
            </div>
        </div>

        @unless ($member->isPremium)
            @php($plansUrl = $nav->url('plans'))
            @if ($plansUrl)
                <div class="membership-box">
                    <p>{{ __('Upgrade membership to call/chat with matches and unlock premium features.') }}</p>
                    <a href="{{ $plansUrl }}" class="upgrade-btn" wire:navigate>{{ __('Upgrade Now') }}</a>
                </div>
            @endif
        @endunless

        <div class="profile-links">
            @foreach ([
                ['member.profile.me', 'My Profile', 'fa-user-o', ['member.profile.me']],
                ['member.profile.me', 'Edit Profile & Preferences', 'fa-pencil', ['member.onboarding']],
                ['member.messages', 'Messages', 'fa-envelope-o', ['member.messages']],
                ['plans', 'Packages & FAQ', 'fa-question-circle-o', ['plans'], '#faq'],
                ['contact', 'Contact', 'fa-phone', ['contact']],
                ['privacy', 'Privacy Policy', 'fa-shield', ['privacy']],
            ] as $item)
                @php($url = $nav->url($item[0]))
                @if ($url)
                    @php($active = $nav->isActive($item[3]))
                    <a href="{{ $url }}{{ $item[4] ?? '' }}" @class(['active' => $active]) @if ($active) aria-current="page" @endif wire:navigate>
                        <i class="fa {{ $item[2] }}" aria-hidden="true"></i>
                        {{ __($item[1]) }}
                    </a>
                @endif
            @endforeach

            {{-- Logout is a POST (P1.1). Until the route exists nothing is rendered. --}}
            @php($logoutUrl = $nav->url('logout'))
            @if ($logoutUrl)
                <form method="POST" action="{{ $logoutUrl }}">
                    @csrf
                    <button type="submit" class="profile-logout">
                        <i class="fa fa-sign-out" aria-hidden="true"></i>
                        {{ __('Logout') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</li>
