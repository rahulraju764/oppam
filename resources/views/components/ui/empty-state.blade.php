{{--
    <x-ui.empty-state> — every list uses it when there is nothing to show, with a next step.
    icon (FA 4.7 class), title, message; the default slot is the action (a button/link).
--}}
@props(['icon' => 'fa-heart-o', 'title', 'message' => null])

<div {{ $attributes->class('ui-empty') }}>
    <span class="ui-empty__icon" aria-hidden="true"><i class="fa {{ $icon }}"></i></span>
    <h3 class="ui-empty__title">{{ $title }}</h3>
    @if ($message)
        <p class="ui-empty__message">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
