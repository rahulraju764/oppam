{{--
    <x-wizard.check-list> — a long multi-select (castes, education, districts …) as a scrollable
    list of real checkboxes inside a fieldset (M02 step 4). Nothing ticked = "Any". model = the
    wire:model array (e.g. "preference.caste_ids"); options = value => label; empty = text shown
    when there is nothing to choose yet.
--}}
@props(['label', 'model', 'options' => [], 'empty' => null, 'hint' => null])

@php
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id = str_replace('.', '-', $model);
    $hasError = $errors->has($model) || $errors->has($model.'.*');
    $error = $errors->first($model) ?: $errors->first($model.'.*');
@endphp

<fieldset class="form-group wizard-checklist" @if ($hasError) aria-describedby="{{ $id }}-error" @endif>
    <legend class="form-label">{{ $label }} <span class="text-muted-brand">({{ __('leave empty for any') }})</span></legend>
    @if ($options === [])
        <p class="form-text mb-0">{{ $empty ?? __('Nothing to choose yet.') }}</p>
    @else
        <div class="wizard-checklist__items">
            @foreach ($options as $value => $optionLabel)
                <div class="form-check" wire:key="{{ $id }}-{{ $value }}">
                    <input class="form-check-input" type="checkbox" id="{{ $id }}-{{ $value }}" name="{{ $id }}[]"
                           value="{{ $value }}" wire:model="{{ $model }}">
                    <label class="form-check-label" for="{{ $id }}-{{ $value }}">{{ $optionLabel }}</label>
                </div>
            @endforeach
        </div>
    @endif
    @if ($hint)
        <small class="text-muted-brand">{{ $hint }}</small>
    @endif
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $error }}</p>
    @endif
</fieldset>
