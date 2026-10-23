{{--
    Bureau portal nav (§11A B.11): the bureau's first menu items as header links on desktop (the
    sidebar carries the full, permission-filtered menu), and the bureau chip. Same classes as the
    public nav (template header.php).

    broker  App\Data\Broker\BrokerChromeData
--}}
@inject('nav', 'App\Support\Navigation\Navigation')

@foreach (array_slice(array_values(array_filter($broker->menu, fn (array $item): bool => ! $item['locked'])), 0, 3) as $item)
    @php($active = $nav->isActive($item['route']))
    <li class="nav-item">
        <a @class(['nav-link head-link', 'active' => $active]) href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif>
            <i class="fa {{ $item['icon'] }}" aria-hidden="true"></i>
            <span>{{ $item['label'] }}</span>
        </a>
    </li>
@endforeach

<li class="nav-item nav-cta">
    <span class="chip broker-chip">{{ $broker->code }} · {{ $broker->roleLabel }}</span>
</li>
