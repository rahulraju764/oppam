{{--
    <x-wizard.option-buttons> — the template's single-choice button group (.option-btns) bound to a
    Livewire property (M02). Buttons are real <button type="button"> with aria-pressed; the group
    is labelled for screen readers. Choosing sets the property (wire:click → $set), so the server
    owns the value; option-buttons.js only paints the active state instantly. The click also
    dispatches `change` so the wizard's idle autosave timer restarts.
    model = property path, selected = current value, options = value => label.
--}}
@props(['label', 'model', 'options' => [], 'selected' => '', 'required' => false, 'disabled' => false])

@php
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id = str_replace('.', '-', $model);
    $hasError = $errors->has($model);
@endphp

<div class="form-group">
    <span class="form-label d-block" id="{{ $id }}-label">{{ $label }}@if ($required) *@endif</span>
    <div class="option-btns" role="group" aria-labelledby="{{ $id }}-label"
         @if ($hasError) aria-describedby="{{ $id }}-error" @endif>
        @foreach ($options as $value => $optionLabel)
            <button type="button" wire:key="{{ $id }}-{{ $value }}"
                    wire:click="$set('{{ $model }}', '{{ $value }}')"
                    x-on:click="$dispatch('change')"
                    @class(['active' => (string) $selected === (string) $value])
                    aria-pressed="{{ (string) $selected === (string) $value ? 'true' : 'false' }}"
                    @disabled($disabled)>{{ $optionLabel }}</button>
        @endforeach
    </div>
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($model) }}</p>
    @endif
</div>
