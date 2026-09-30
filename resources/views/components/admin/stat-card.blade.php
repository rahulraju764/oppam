{{-- <x-admin.stat-card> — one KPI tile (PRD A02). label, value, hint (definition / "as of"). --}}
@props(['label', 'value', 'hint' => null])

<div {{ $attributes->class('admin-stat') }}>
    <span class="admin-stat__label">{{ $label }}</span>
    <span class="admin-stat__value">{{ $value }}</span>
    @if ($hint)
        <span class="admin-stat__hint">{{ $hint }}</span>
    @endif
</div>
