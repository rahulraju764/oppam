{{-- /broker/verify-device (§8.1) — the template login card with the OTP field (as /verify-otp). --}}
<div>
    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="login-form">
                    <div class="login-head">
                        <h1>{{ __('Confirm this device') }}</h1>
                        <p>{{ __('For your bureau’s safety, owners and managers confirm each new device once. We’ll text a code to :phone.', ['phone' => $maskedPhone]) }}</p>
                    </div>

                    @if (! $sent)
                        <button type="button" class="btn login-btn" wire:click="send" dusk="device-send" wire:loading.attr="disabled" wire:target="send">
                            <span class="ui-spinner" wire:loading wire:target="send" aria-hidden="true"></span>
                            {{ __('Send code') }}
                        </button>
                        @error('code')<p class="invalid-feedback d-block" role="alert">{{ $message }}</p>@enderror
                    @else
                        <form wire:submit="verify" novalidate>
                            <div class="login-field">
                                <label for="deviceCode" class="login-label">{{ __('6-digit code') }}</label>
                                <input type="text" id="deviceCode" name="code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required
                                       class="form-control login-input @error('code') is-invalid @enderror"
                                       @error('code') aria-invalid="true" aria-describedby="deviceCode-error" @enderror>
                                @error('code')<p class="invalid-feedback d-block" id="deviceCode-error">{{ $message }}</p>@enderror
                            </div>
                            <button type="submit" class="btn login-btn" dusk="device-verify" wire:loading.attr="disabled" wire:target="verify">
                                <span class="ui-spinner" wire:loading wire:target="verify" aria-hidden="true"></span>
                                {{ __('Confirm device') }}
                            </button>
                            <p class="login-prompt">
                                <button type="button" class="ui-link-btn" wire:click="send" wire:loading.attr="disabled" wire:target="send">{{ __('Send a new code') }}</button>
                            </p>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>
