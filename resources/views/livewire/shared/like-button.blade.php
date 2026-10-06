{{--
    The Like ♥ button (PRD M06). `engageToggle` (resources/js/alpine/engagement.js) flips it at once
    and the server answers with the real state. Variants: row (list rows), page (profile page),
    tile (icon over a slider tile). A toggle button: one stable name ("Like Anna T.") and
    aria-pressed says whether it is on. While its own request runs it is aria-disabled (it keeps
    focus) and further clicks are ignored.
--}}
@php($label = $name !== '' ? __('Like :name', ['name' => $name]) : __('Like'))
<span class="engage engage--{{ $variant }}" x-data="engageToggle(@js($liked), 'like', @js($profileCode), 'like-changed')">
    <button type="button"
            @class(['like-btn', 'send-int' => $variant === 'page', 'is-on' => $liked])
            x-bind:class="{ 'is-on': on, 'is-busy': busy }" x-on:click="toggle()"
            x-bind:aria-disabled="busy.toString()" x-bind:aria-busy="busy.toString()"
            aria-pressed="{{ $liked ? 'true' : 'false' }}" x-bind:aria-pressed="on.toString()"
            aria-label="{{ $label }}">
        <i @class(['fa', $liked ? 'fa-heart' : 'fa-heart-o']) x-bind:class="on ? 'fa-heart' : 'fa-heart-o'" aria-hidden="true"></i>
        @unless ($variant === 'tile')
            <span aria-hidden="true" x-text="on ? @js(__('Liked')) : @js(__('Like'))">{{ $liked ? __('Liked') : __('Like') }}</span>
        @endunless
    </button>
</span>
