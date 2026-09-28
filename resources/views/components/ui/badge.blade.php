{{--
    <x-ui.badge> — a .chip with a mapped colour. Colour is never the only signal: the slot text
    says what it means. variant: default | verified | premium | muted | success | warning
--}}
@props(['variant' => 'default', 'icon' => null])

@php
    $variantClass = match ($variant) {
        'verified' => 'chip--verified',
        'premium' => 'chip--premium',
        'muted' => 'chip--muted',
        'success' => 'chip--success',
        'warning' => 'chip--warning',
        default => null,
    };
@endphp

<span {{ $attributes->class(['chip', $variantClass]) }}>
    @if ($icon)<i class="fa {{ $icon }}" aria-hidden="true"></i>@endif
    {{ $slot }}
</span>
