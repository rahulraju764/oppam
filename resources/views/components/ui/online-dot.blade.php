{{--
    Presence dot next to an avatar. Placeholder until presence lands (P3.1): it renders only a
    static "online" state the server already knows; live updates come from the presence store.
    online  bool
--}}
@props(['online' => false])

@if ($online)
    <span {{ $attributes->class('online-dot') }}>
        <span class="visually-hidden">{{ __('Online') }}</span>
    </span>
@endif
