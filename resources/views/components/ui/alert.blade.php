{{--
    <x-ui.alert> — type success | info | warning | danger. Danger uses role="alert" (announced
    assertively); the rest role="status". dismissible adds an Alpine close button.
--}}
@props(['type' => 'info', 'dismissible' => false, 'title' => null])

@php
    $typeClass = match ($type) {
        'success' => 'ui-alert--success',
        'warning' => 'ui-alert--warning',
        'danger' => 'ui-alert--danger',
        default => 'ui-alert--info',
    };
@endphp

<div {{ $attributes->class(['alert ui-alert', $typeClass, 'alert-dismissible' => $dismissible]) }}
     role="{{ $type === 'danger' ? 'alert' : 'status' }}"
     @if ($dismissible) x-data="{ open: true }" x-show="open" @endif>
    @if ($title)
        <strong class="d-block">{{ $title }}</strong>
    @endif
    {{ $slot }}
    @if ($dismissible)
        <button type="button" class="btn-close" x-on:click="open = false" aria-label="{{ __('Dismiss') }}"></button>
    @endif
</div>
