{{-- <x-ui.textarea> — same contract as <x-ui.input> (label, name, id, hint, required, error). --}}
@props(['label', 'name', 'id' => null, 'hint' => null, 'required' => false, 'error' => null, 'rows' => 4])

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
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
              {{ $attributes->class(['form-control mat-text', 'is-invalid' => $hasError]) }}
              @if ($required) required aria-required="true" @endif
              @if ($hasError) aria-invalid="true" @endif
              @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>{{ $slot }}</textarea>
    @if ($hint)
        <p class="form-text" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
