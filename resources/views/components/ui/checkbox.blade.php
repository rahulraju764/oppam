{{-- <x-ui.checkbox> — a labelled checkbox; the label slot may contain links. name, id, value, error. --}}
@props(['name', 'id' => null, 'value' => '1', 'error' => null])

@php
    // Shared by ShareErrorsFromSession / Livewire; absent when rendered outside a request.
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id ??= 'f-'.str_replace(['.', '[', ']'], '-', $name);
    $errorKey = $error ?? ($attributes->wire('model')->value() ?: $name);
    $hasError = $errors->has($errorKey);
@endphp

<div class="form-check ui-field">
    <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}"
           {{ $attributes->class(['form-check-input', 'is-invalid' => $hasError]) }}
           @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
    <label class="form-check-label" for="{{ $id }}">{{ $slot }}</label>
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
