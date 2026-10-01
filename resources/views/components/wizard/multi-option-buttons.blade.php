{{--
    <x-wizard.multi-option-buttons> — the template's .option-btns group as a MULTI-select (partner
    preferences, template partner.php "Any / Unmarried / …"). "Any" clears the list; every other
    button toggles one value through the wizard's toggleChoice()/clearChoice() actions, so the
    server owns the list. Buttons carry aria-pressed. list = the PreferenceForm list name.
--}}
@props(['label', 'list', 'options' => [], 'selected' => [], 'required' => false])

@php
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $id = 'preference-'.$list;
    $model = 'preference.'.$list;
    $selected = array_map('strval', $selected);
    $hasError = $errors->has($model) || $errors->has($model.'.*');
    $error = $errors->first($model) ?: $errors->first($model.'.*');
@endphp

<div class="form-group">
    <span class="form-label d-block" id="{{ $id }}-label">{{ $label }}@if ($required) *@endif</span>
    <div class="option-btns" role="group" aria-labelledby="{{ $id }}-label"
         @if ($hasError) aria-describedby="{{ $id }}-error" @endif>
        @unless ($required)
            <button type="button" wire:key="{{ $id }}-any" wire:click="clearChoice('{{ $list }}')" x-on:click="$dispatch('change')"
                    @class(['active' => $selected === []]) aria-pressed="{{ $selected === [] ? 'true' : 'false' }}">{{ __('Any') }}</button>
        @endunless
        @foreach ($options as $value => $optionLabel)
            @php($on = in_array((string) $value, $selected, true))
            <button type="button" wire:key="{{ $id }}-{{ $value }}"
                    wire:click="toggleChoice('{{ $list }}', '{{ $value }}')" x-on:click="$dispatch('change')"
                    @class(['active' => $on]) aria-pressed="{{ $on ? 'true' : 'false' }}">{{ $optionLabel }}</button>
        @endforeach
    </div>
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $error }}</p>
    @endif
</div>
