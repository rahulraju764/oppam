{{--
    <x-ui.input> — labelled text-like input with hint and server error (template .mat-label /
    .mat-text). Every control gets name, id and a real <label for>.
    label, name (required) · id (default: derived from name) · type · hint · required ·
    error — the error-bag key (default: the wire:model target, else name).
    Pass wire:model / placeholder / autocomplete etc. as attributes.
--}}
@props(['label', 'name', 'id' => null, 'type' => 'text', 'hint' => null, 'required' => false, 'error' => null])

@php
    // Shared by ShareErrorsFromSession / Livewire; absent when rendered outside a request.
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id ??= 'f-'.str_replace(['.', '[', ']'], '-', $name);
    $errorKey = $error ?? ($attributes->wire('model')->value() ?: $name);
    $hasError = $errors->has($errorKey);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div class="ui-field">
    <label class="mat-label" for="{{ $id }}">
        {{ $label }}@if ($required)<span class="required-mark" aria-hidden="true"> *</span>@endif
    </label>
    <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
           {{ $attributes->class(['form-control mat-text', 'is-invalid' => $hasError]) }}
           @if ($required) required aria-required="true" @endif
           @if ($hasError) aria-invalid="true" @endif
           @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    @if ($hint)
        <p class="form-text" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
