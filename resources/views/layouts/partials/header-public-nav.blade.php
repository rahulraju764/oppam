{{-- Public (logged-out) nav items — template header.php "PUBLIC NAV". Unbuilt routes are skipped. --}}
@inject('nav', 'App\Support\Navigation\Navigation')

@foreach ([
    ['home', 'Home', 'fa-home'],
    ['plans', 'Packages & FAQ', 'fa-diamond'],
    ['contact', 'Contact', 'fa-phone'],
    ['login', 'Login', 'fa-sign-in'],
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

@php($registerUrl = $nav->url('register'))
@if ($registerUrl)
    <li class="nav-item nav-cta">
        <a href="{{ $registerUrl }}" class="upgrade-btn" wire:navigate>{{ __('Register Free') }}</a>
    </li>
@endif
