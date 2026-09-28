{{-- <x-ui.skeleton> — loading placeholder for #[Lazy] sections. shape: line | row | card | avatar; count --}}
@props(['shape' => 'line', 'count' => 1])

@php
    $shapeClass = match ($shape) {
        'avatar' => 'ui-skeleton--avatar',
        'card' => 'ui-skeleton--card',
        'row' => 'ui-skeleton--row',
        default => 'ui-skeleton--line',
    };
@endphp

<div {{ $attributes->class('d-grid gap-3') }} aria-hidden="true">
    @for ($i = 0; $i < $count; $i++)
        <span class="ui-skeleton {{ $shapeClass }}"></span>
    @endfor
</div>
