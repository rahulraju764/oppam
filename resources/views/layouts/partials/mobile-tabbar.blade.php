{{--
    Member mobile tab bar + bottom sheets (template footer2.php). Member layout only.
    Home | Matches (sheet) | Interests (sheet) | Search | Profile. Sheets open via
    aria-controls (tab-sheet.js). On the wizard the page's prev/next are repeated in the bar,
    because the top page-nav has scrolled away by the bottom of a long form.
    Interest box counts become live in P3.4 (the template's numbers were demo data).
--}}
@inject('nav', 'App\Support\Navigation\Navigation')
@php($matchesLinks = $nav->matchesLinks())
@php($interestsUrl = $nav->url('member.interests'))
@php($plansUrl = $nav->url('plans'))
@php($wizard = $nav->isActive('member.onboarding') ? $pageNav : null)
@php($dashboardUrl = $nav->url('member.dashboard'))
@php($searchUrl = $nav->url('member.search'))
@php($myProfileUrl = $nav->url('member.profile.me'))

{{-- No tabs built yet → no bar (an empty fixed bar would only cover content). --}}
@if ($dashboardUrl || $matchesLinks !== [] || $interestsUrl || $searchUrl || $myProfileUrl)
<nav class="mobile-footer d-lg-none" aria-label="{{ __('Primary') }}">

    @if ($wizard)
        <div class="wizard-steps">
            @if ($wizard->prev)
                <a class="wizard-step" href="{{ $wizard->prev->url }}" wire:navigate>
                    <i class="fa fa-angle-left" aria-hidden="true"></i>
                    <span>{{ __('Prev') }}<span class="wizard-step-to">: {{ $wizard->prev->label }}</span></span>
                </a>
            @endif
            <p class="wizard-steps-title">{{ $wizard->title }}</p>
            @if ($wizard->next)
                <a class="wizard-step is-next" href="{{ $wizard->next->url }}" wire:navigate>
                    <span>{{ __('Next') }}<span class="wizard-step-to">: {{ $wizard->next->label }}</span></span>
                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    @endif

    @if ($dashboardUrl)
        @php($active = $nav->isActive('member.dashboard'))
        <a href="{{ $dashboardUrl }}" @class(['is-current' => $active]) @if ($active) aria-current="page" @endif wire:navigate>
            <i class="fa fa-home" aria-hidden="true"></i>
            <span>{{ __('Home') }}</span>
        </a>
    @endif

    @if ($matchesLinks !== [])
        <button type="button" @class(['tab-sheet-toggle', 'is-current' => $nav->inMatchesSection()]) aria-expanded="false" aria-controls="matches-sheet">
            <i class="fa fa-heart" aria-hidden="true"></i>
            <span>{{ __('Matches') }}</span>
        </button>
    @endif

    @if ($interestsUrl)
        <button type="button" @class(['tab-sheet-toggle', 'is-current' => $nav->isActive('member.interests')]) aria-expanded="false" aria-controls="interests-sheet">
            <i class="fa fa-address-book" aria-hidden="true"></i>
            <span>{{ __('Interests') }}</span>
        </button>
    @endif

    @if ($searchUrl)
        @php($active = $nav->isActive('member.search'))
        <a href="{{ $searchUrl }}" @class(['is-current' => $active]) @if ($active) aria-current="page" @endif wire:navigate>
            <i class="fa fa-search" aria-hidden="true"></i>
            <span>{{ __('Search') }}</span>
        </a>
    @endif

    @if ($myProfileUrl)
        <a href="{{ $myProfileUrl }}" @class(['is-current' => $nav->inProfileSection()]) @if ($nav->isActive('member.profile.me')) aria-current="page" @endif wire:navigate>
            <i class="fa fa-user-o" aria-hidden="true"></i>
            <span>{{ __('Profile') }}</span>
        </a>
    @endif
</nav>
@endif

@if ($matchesLinks !== [])
    <div class="tab-sheet d-lg-none" id="matches-sheet" hidden role="dialog" aria-modal="true" aria-label="{{ __('Matches') }}">
        <div class="tab-sheet-panel">
            <div class="tab-sheet-grip" aria-hidden="true"></div>
            <div class="tab-sheet-head">
                <p class="tab-sheet-title">{{ __('Matches') }}</p>
                <button type="button" class="tab-sheet-close" aria-label="{{ __('Close Matches') }}">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div class="tab-sheet-body">
                <p class="tab-sheet-group">{{ __('Browse') }}</p>
                <div class="tab-sheet-links">
                    @foreach ($matchesLinks as $link)
                        <a href="{{ $link->url }}" @class(['tab-sheet-link', 'active' => $link->active]) @if ($link->active) aria-current="page" @endif wire:navigate>
                            <i class="fa {{ $link->icon }}" aria-hidden="true"></i>
                            <span>{{ $link->label }}</span>
                            <small class="tab-sheet-link-desc">{{ $link->description }}</small>
                        </a>
                    @endforeach
                </div>

                <p class="tab-sheet-group">{{ __('Go to') }}</p>
                <div class="tab-sheet-links">
                    @if ($interestsUrl)
                        <a href="{{ $interestsUrl }}" @class(['tab-sheet-link', 'active' => $nav->isActive('member.interests')]) wire:navigate>
                            <i class="fa fa-address-book" aria-hidden="true"></i>
                            <span>{{ __('Interests') }}</span>
                        </a>
                    @endif
                    @if ($plansUrl)
                        <a href="{{ $plansUrl }}" @class(['tab-sheet-link', 'active' => $nav->isActive('plans')]) wire:navigate>
                            <i class="fa fa-diamond" aria-hidden="true"></i>
                            <span>{{ __('Upgrade') }}</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

@if ($interestsUrl)
    <div class="tab-sheet d-lg-none" id="interests-sheet" hidden role="dialog" aria-modal="true" aria-label="{{ __('Interests') }}">
        <div class="tab-sheet-panel">
            <div class="tab-sheet-grip" aria-hidden="true"></div>
            <div class="tab-sheet-head">
                <p class="tab-sheet-title">{{ __('Interests') }}</p>
                <button type="button" class="tab-sheet-close" aria-label="{{ __('Close Interests') }}">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div class="tab-sheet-body">
                @foreach (['received' => __('Interests Received'), 'sent' => __('Interests Sent')] as $box => $boxLabel)
                    <p @class(['tab-sheet-group', 'is-sent' => $box === 'sent', 'is-received' => $box === 'received'])>{{ $boxLabel }}</p>
                    <div class="tab-sheet-list">
                        @foreach (['all' => __('All'), 'pending' => __('Pending'), 'accepted' => __('Accepted / replied'), 'declined' => __('Declined')] as $status => $statusLabel)
                            <a href="{{ $interestsUrl }}?box={{ $box }}{{ $status !== 'all' ? '&status='.$status : '' }}" class="tab-sheet-item" wire:navigate>{{ $statusLabel }}</a>
                        @endforeach
                    </div>
                @endforeach

                <p class="tab-sheet-group">{{ __('Go to') }}</p>
                <div class="tab-sheet-links">
                    @if ($matchesLinks !== [])
                        <a href="{{ $matchesLinks[0]->url }}" class="tab-sheet-link" wire:navigate>
                            <i class="fa fa-heart" aria-hidden="true"></i>
                            <span>{{ __('Matches') }}</span>
                        </a>
                    @endif
                    @if ($plansUrl)
                        <a href="{{ $plansUrl }}" class="tab-sheet-link" wire:navigate>
                            <i class="fa fa-diamond" aria-hidden="true"></i>
                            <span>{{ __('Upgrade') }}</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
