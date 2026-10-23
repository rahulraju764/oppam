{{--
    Bureau portal nav (§11A B.11): the bureau's first menu items as header links on desktop (the
    sidebar carries the full, permission-filtered menu), and the bureau chip. Below lg the header
    becomes the drawer and the sidebar is hidden on phones (template responsive.css), so the drawer
    carries the whole menu — locked items with their reason — and sign-out. Same classes as the
    public nav (template header.php).

    broker  App\Data\Broker\BrokerChromeData
--}}
@inject('nav', 'App\Support\Navigation\Navigation')

@php($shown = 0)
@foreach ($broker->menu as $item)
    @if ($item['locked'])
        <li class="nav-item d-lg-none">
            <span class="nav-link head-link broker-nav-locked" aria-disabled="true">
                <i class="fa {{ $item['icon'] }}" aria-hidden="true"></i>
                <span>{{ $item['label'] }}</span>
                <small><i class="fa fa-lock" aria-hidden="true"></i> {{ __('after KYC') }}</small>
            </span>
        </li>
    @else
        @php($active = $nav->isActive($item['route']))
        <li @class(['nav-item', 'd-lg-none' => $shown >= 3])>
            <a @class(['nav-link head-link', 'active' => $active]) href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif>
                <i class="fa {{ $item['icon'] }}" aria-hidden="true"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        </li>
        @php($shown++)
    @endif
@endforeach

<li class="nav-item nav-cta">
    <span class="chip broker-chip">{{ $broker->code }} · {{ $broker->roleLabel }}</span>
</li>

{{-- Sign-out is a POST through Livewire (M01), never a GET link; desktop has it in the sidebar. --}}
<li class="nav-item d-lg-none broker-nav-logout">
    <livewire:member.auth.logout-button :key="'broker-drawer-logout'" />
</li>
