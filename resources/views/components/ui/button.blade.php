{{--
    <x-ui.button> — renders <a> only when href is given; otherwise a <button> (type=submit by
    default, so inside a form it submits; pass type="button" for plain actions).
    variant  primary | secondary | outline | ghost | danger | link
    size     sm | md | lg
    icon     Font Awesome 4.7 class without the "fa " prefix, e.g. "fa-heart"
    loading  a Livewire action name: shows a spinner and disables the button while it runs
             (wire:loading + wire:target) — no double submits.
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'submit', 'icon' => null, 'loading' => null])

@php
    // Explicit maps (oppam-ui-standards): never build class names from input.
    $variantClass = match ($variant) {
        'secondary' => 'ui-btn--secondary',
        'outline' => 'ui-btn--outline',
        'ghost' => 'ui-btn--ghost',
        'danger' => 'ui-btn--danger',
        'link' => 'ui-btn--link',
        default => 'ui-btn--primary',
    };
    $sizeClass = match ($size) {
        'sm' => 'ui-btn--sm',
        'lg' => 'ui-btn--lg',
        default => null,
    };
    $classes = collect(['ui-btn', $variantClass, $sizeClass])->filter()->implode(' ');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<i class="fa {{ $icon }}" aria-hidden="true"></i>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}
        @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif>
        @if ($loading)
            <span class="ui-spinner" wire:loading wire:target="{{ $loading }}" aria-hidden="true"></span>
        @endif
        @if ($icon)
            <i class="fa {{ $icon }}" aria-hidden="true" @if ($loading) wire:loading.remove wire:target="{{ $loading }}" @endif></i>
        @endif
        {{ $slot }}
    </button>
@endif
