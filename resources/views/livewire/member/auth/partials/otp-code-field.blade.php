{{-- The 6-digit code input + "Resend in 0:27" link, shared by the OTP screens (M01).
     $resendAction — the Livewire method that sends a new code. --}}
<div class="login-field">
    <label for="otpCode" class="login-label">{{ __('6-digit code') }}</label>
    <input type="text" id="otpCode" name="code" wire:model="code" required
           inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"
           class="form-control login-input @error('code') is-invalid @enderror" placeholder="••••••"
           @error('code') aria-invalid="true" aria-describedby="otpCode-error" @enderror>
    @error('code')<p class="invalid-feedback d-block" id="otpCode-error" role="alert">{{ $message }}</p>@enderror
</div>

<div class="login-meta" x-data="otpCountdown({{ (int) $resendIn }})" x-on:otp-resent.window="restart()">
    <span class="form-text" x-show="left > 0" aria-live="polite">
        {{ __('Resend code in') }} <span x-text="label"></span>
    </span>
    <button type="button" class="login-forgot btn btn-link p-0" x-show="left === 0" x-cloak
            wire:click="{{ $resendAction }}" wire:loading.attr="disabled" wire:target="{{ $resendAction }}">
        {{ __('Resend code') }}
    </button>
</div>
@error('resend')<p class="invalid-feedback d-block" role="alert">{{ $message }}</p>@enderror
