{{--
    <x-wizard.input> — a template wizard field (.form-group > label + .form-control) with its server
    error (M02). model = the wire:model target; other attributes (type, maxlength, placeholder,
    autocomplete, inputmode, min, max) pass through to the <input>. textarea = true renders one.
--}}
@props(['label', 'model', 'required' => false, 'textarea' => false, 'hint' => null])

@php
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id = str_replace('.', '-', $model);
    $hasError = $errors->has($model);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div class="form-group">
    <label for="{{ $id }}">{{ $label }}@if ($required) *@endif</label>
    @if ($textarea)
        <textarea id="{{ $id }}" name="{{ $id }}" wire:model="{{ $model }}" rows="4"
                  {{ $attributes->class(['form-control', 'is-invalid' => $hasError]) }}
                  @if ($required) aria-required="true" @endif
                  @if ($hasError) aria-invalid="true" @endif
                  @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif></textarea>
    @else
        <input id="{{ $id }}" name="{{ $id }}" wire:model="{{ $model }}"
               {{ $attributes->merge(['type' => 'text'])->class(['form-control', 'is-invalid' => $hasError]) }}
               @if ($required) aria-required="true" @endif
               @if ($hasError) aria-invalid="true" @endif
               @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    @endif
    @if ($hint)
        <small id="{{ $id }}-hint" class="text-muted-brand">{{ $hint }}</small>
    @endif
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($model) }}</p>
    @endif
</div>
