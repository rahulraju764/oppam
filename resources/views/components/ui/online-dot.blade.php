{{--
    Presence dot next to an avatar or name (PRD §9.6).
    code    a profile code: the dot shows live while that member is in the presence.online roster
            ($store.online, kept by resources/js/realtime.js) — no Livewire round trip.
    online  bool: a static "online" the server already knows (no code given).
--}}
@props(['code' => null, 'online' => false])

@if ($code !== null)
    <span {{ $attributes->class('online-dot') }} x-data x-show="$store.online && $store.online.has(@js($code))" x-cloak
          data-online-code="{{ $code }}">
        <span class="visually-hidden">{{ __('Online') }}</span>
    </span>
@elseif ($online)
    <span {{ $attributes->class('online-dot') }}>
        <span class="visually-hidden">{{ __('Online') }}</span>
    </span>
@endif
