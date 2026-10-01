{{--
    <x-wizard.select> — a template wizard field (.form-group > label + .form-select) with its server
    error (M02). model = the wire:model target (e.g. "basic.caste_id"); options = value => label.
    live = re-render on change (dependent selects).
--}}
@props(['label', 'model', 'options' => [], 'required' => false, 'placeholder' => null, 'live' => false, 'hint' => null])

@php
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id = str_replace('.', '-', $model);
    $hasError = $errors->has($model);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div {{ $attributes->class('form-group') }}>
    <label for="{{ $id }}">{{ $label }}@if ($required) *@endif</label>
    <select id="{{ $id }}" name="{{ $id }}" class="form-select @if ($hasError) is-invalid @endif"
            @if ($live) wire:model.live="{{ $model }}" @else wire:model="{{ $model }}" @endif
            @if ($required) aria-required="true" @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
        <option value="">{{ $placeholder ?? __('Select') }}</option>
        @foreach ($options as $value => $optionLabel)
            <option value="{{ $value }}" wire:key="{{ $id }}-{{ $value }}">{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($hint)
        <small id="{{ $id }}-hint" class="text-muted-brand">{{ $hint }}</small>
    @endif
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($model) }}</p>
    @endif
</div>
