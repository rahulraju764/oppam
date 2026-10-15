{{--
    <x-admin.stat-card> — one KPI tile (PRD A02).
    label, value          what and how much
    hint                  small line under the value ("as of", a definition)
    delta                 change against 30 days earlier, e.g. "+12%" (optional)
    definition            what the number means — shown as a tooltip and read by screen readers
    tick                  metric key: the value follows live `dashboard-tick` window events for it
--}}
@props(['label', 'value', 'hint' => null, 'delta' => null, 'definition' => null, 'tick' => null])

<div {{ $attributes->class('admin-stat') }} @if ($definition) title="{{ $definition }}" @endif
     @if ($tick) x-data="{ shown: null }" x-on:dashboard-tick.window="if ($event.detail.metric === @js($tick)) shown = Number($event.detail.value).toLocaleString()" @endif>
    <span class="admin-stat__label">{{ $label }}</span>
    <span class="admin-stat__value" @if ($tick) x-text="shown ?? @js((string) $value)" aria-live="polite" @endif>{{ $value }}</span>
    @if ($delta)
        <span @class(['admin-stat__delta', 'is-down' => str_starts_with($delta, '-')])>{{ $delta }} <span class="visually-hidden">{{ __('against 30 days earlier') }}</span></span>
    @endif
    @if ($hint)
        <span class="admin-stat__hint">{{ $hint }}</span>
    @endif
    @if ($definition)
        <span class="visually-hidden">{{ $definition }}</span>
    @endif
</div>
