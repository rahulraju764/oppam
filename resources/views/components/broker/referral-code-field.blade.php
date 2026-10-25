{{--
    <x-broker.referral-code-field> — the optional bureau referral code (§11A B.5) on /register and
    wizard step 1. The hint under it comes from BrokerCodeLookup::hint (bureau name + district only);
    an unknown code is accepted with a neutral note — it never blocks the form (R-M13-1).

    id     input id
    model  wire:model path (debounced live, for the hint)
    hint   ?App\Data\Broker\ReferralCodeHintData — null while the field is empty
--}}
@props(['id', 'model', 'hint' => null, 'wrapperClass' => 'login-field', 'inputClass' => 'form-control login-input', 'labelClass' => 'login-label'])

<div class="{{ $wrapperClass }} referral-code-field">
    <label for="{{ $id }}" class="{{ $labelClass }}">{{ __('Bureau referral code (optional)') }}</label>
    <input type="text" id="{{ $id }}" name="referral_code" wire:model.live.debounce.400ms="{{ $model }}" maxlength="30"
           autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="BRK1042"
           class="{{ $inputClass }} @error($model) is-invalid @enderror"
           aria-describedby="{{ $id }}-hint @error($model) {{ $id }}-error @enderror"
           @error($model) aria-invalid="true" @enderror>
    @error($model)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $message }}</p>
    @enderror
    <p class="form-text referral-code-field__hint" id="{{ $id }}-hint" aria-live="polite" wire:loading.remove wire:target="{{ $model }}">
        @if ($hint === null)
            {{ __('Did a marriage bureau send you? Enter its code.') }}
        @elseif ($hint->valid)
            <i class="fa fa-check-circle" aria-hidden="true"></i>
            {{ $hint->district !== null ? __(':name, :district', ['name' => $hint->name, 'district' => $hint->district]) : $hint->name }}
        @else
            {{ __('We couldn’t find this code. You can still continue — our team will check it.') }}
        @endif
    </p>
    <p class="form-text referral-code-field__hint" wire:loading wire:target="{{ $model }}">{{ __('Checking…') }}</p>
</div>
