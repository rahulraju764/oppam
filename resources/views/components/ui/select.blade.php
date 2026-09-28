{{--
    <x-ui.select> — same contract as <x-ui.input>. options: value => label (from the Masters
    service or an enum — never a hardcoded list in a view); placeholder adds an empty first
    option. Extra <option>s can go in the default slot.
--}}
@props(['label', 'name', 'id' => null, 'options' => [], 'placeholder' => null, 'selected' => null, 'hint' => null, 'required' => false, 'error' => null])

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
    <select id="{{ $id }}" name="{{ $name }}"
            {{ $attributes->class(['form-select mat-text', 'is-invalid' => $hasError]) }}
            @if ($required) required aria-required="true" @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $optionLabel)
            <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $optionLabel }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($hint)
        <p class="form-text" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
