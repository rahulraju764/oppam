{{-- <x-ui.card> — the template's card shell. Slots: header, default (body), footer. variant: default | accent --}}
@props(['variant' => 'default'])

<div {{ $attributes->class(['ui-card', 'ui-card--accent' => $variant === 'accent']) }}>
    @isset($header)
        <div class="ui-card__header">{{ $header }}</div>
    @endisset
    <div class="ui-card__body">{{ $slot }}</div>
    @isset($footer)
        <div class="ui-card__footer">{{ $footer }}</div>
    @endisset
</div>
