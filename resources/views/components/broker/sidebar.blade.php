{{--
    <x-broker.sidebar> — the bureau column of the broker portal (template dashboard
    .dashboard-sidebar / .sidebar-profile / .sidebar-menu). Items come from BrokerChromeData,
    already filtered by BureauGate; an item that waits for KYC is shown but inert, with the reason
    in text (never colour only). Hiding a link is not security: every route checks again.

    bureau  App\Data\Broker\BrokerChromeData
--}}
@props(['bureau'])
@inject('nav', 'App\Support\Navigation\Navigation')

<div class="dashboard-sidebar broker-sidebar">
    <div class="sidebar-profile">
        <span class="broker-sidebar__mark" aria-hidden="true"><i class="fa fa-building-o"></i></span>
        <h3>{{ $bureau->bureauName }}</h3>
        <h4>{{ $bureau->code }}</h4>
        <span>{{ $bureau->roleLabel }}</span>
        @if (! $bureau->verified)
            <p class="broker-sidebar__status"><x-ui.badge variant="warning" icon="fa-clock-o">{{ __('KYC: :status', ['status' => $bureau->statusLabel]) }}</x-ui.badge></p>
        @endif
    </div>

    <nav class="sidebar-menu" aria-label="{{ __('Bureau menu') }}">
        @foreach ($bureau->menu as $item)
            @if ($item['locked'])
                <span class="broker-sidebar__locked" aria-disabled="true">
                    <i class="fa {{ $item['icon'] }}" aria-hidden="true"></i>
                    {{ $item['label'] }}
                    <small><i class="fa fa-lock" aria-hidden="true"></i> {{ __('after KYC') }}</small>
                </span>
            @else
                @php($active = $nav->isActive($item['route']))
                <a href="{{ route($item['route']) }}" @class(['active' => $active]) @if ($active) aria-current="page" @endif wire:navigate>
                    <i class="fa {{ $item['icon'] }}" aria-hidden="true"></i>
                    {{ $item['label'] }}
                </a>
            @endif
        @endforeach
    </nav>

    <div class="broker-sidebar__logout">
        <livewire:member.auth.logout-button :key="'broker-logout'" />
    </div>
</div>
