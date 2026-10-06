{{--
    The Favorite ☆ bookmark (PRD M06) — private, never shown to the other member. Variants: photo
    (the template's .short-list control on a row's photo), page (a button on the profile page).
    `engageToggle` flips it at once; the server answers with the real state. A toggle button: one
    stable name and aria-pressed; aria-disabled (keeps focus) while its own request runs.
--}}
@php($label = $name !== '' ? __('Favourite :name', ['name' => $name]) : __('Favourite'))
<span class="engage engage--{{ $variant }}" x-data="engageToggle(@js($favorited), 'favorite', @js($profileCode), 'favorite-changed')">
    <button type="button"
            @class(['fav-btn', 'short-list' => $variant === 'photo', 'send-int' => $variant === 'page', 'is-on' => $favorited])
            x-bind:class="{ 'is-on': on, 'is-busy': busy }" x-on:click="toggle()"
            x-bind:aria-disabled="busy.toString()" x-bind:aria-busy="busy.toString()"
            aria-pressed="{{ $favorited ? 'true' : 'false' }}" x-bind:aria-pressed="on.toString()"
            aria-label="{{ $label }}">
        <span class="short-icon">
            <i @class(['fa', $favorited ? 'fa-star' : 'fa-star-o']) x-bind:class="on ? 'fa-star' : 'fa-star-o'" aria-hidden="true"></i>
        </span>
        @if ($variant === 'page')
            <span aria-hidden="true" x-text="on ? @js(__('Favorited')) : @js(__('Favorite'))">{{ $favorited ? __('Favorited') : __('Favorite') }}</span>
        @endif
    </button>
</span>
