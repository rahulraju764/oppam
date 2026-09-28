{{--
    Member mobile header (< 992px): avatar + name + tier on the left, upgrade + bell on the right.
    The bell count becomes <livewire:shared.notification-bell /> in P3.2 — keep it in step with
    .nav-bell in header-member-nav (same destination, same count).
--}}
@inject('nav', 'App\Support\Navigation\Navigation')
@php($myProfileUrl = $nav->url('member.profile.me'))
@php($plansUrl = $nav->url('plans'))
@php($notificationsUrl = $nav->url('member.notifications'))

<div class="mobile-header d-lg-none">
    @if ($myProfileUrl)
        <a href="{{ $myProfileUrl }}" class="mobile-left" wire:navigate>
    @else
        <div class="mobile-left">
    @endif
        <img src="{{ $member->photoUrl }}" alt="" width="600" height="600" fetchpriority="high" decoding="async">
        <div class="mobile-user-info">
            <h4>{{ $member->name }}</h4>
            <span class="member-badge">{{ __(':plan Member', ['plan' => $member->planLabel]) }}</span>
        </div>
    @if ($myProfileUrl)
        </a>
    @else
        </div>
    @endif

    <div class="mobile-right">
        @if ($plansUrl)
            @php($onPlans = $nav->isActive('plans'))
            <a href="{{ $plansUrl }}" @class(['mobile-upgrade', 'is-current' => $onPlans]) @if ($onPlans) aria-current="page" @endif aria-label="{{ __('Upgrade membership') }}" wire:navigate>
                <i class="fa fa-diamond" aria-hidden="true"></i>
            </a>
        @endif

        @if ($notificationsUrl)
            @php($onNotifications = $nav->isActive('member.notifications'))
            <a href="{{ $notificationsUrl }}" @class(['mobile-bell', 'is-current' => $onNotifications]) @if ($onNotifications) aria-current="page" @endif
               aria-label="{{ trans_choice('Notifications (:count unread)', $member->unreadNotifications, ['count' => $member->unreadNotifications]) }}" wire:navigate>
                <i class="fa fa-bell-o" aria-hidden="true"></i>
                @if ($member->unreadNotifications > 0)
                    <span class="mobile-bell-count" aria-hidden="true">{{ $member->unreadNotifications }}</span>
                @endif
            </a>
        @endif
    </div>
</div>
