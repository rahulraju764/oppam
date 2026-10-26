{{-- /broker/staff/invite/{token} (§11A B.8) — accept a bureau staff invitation. Same card as the login page (template login.php). --}}
<div>
    <h1 class="visually-hidden">{{ __('Join your bureau on Oppam') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h3>{{ __('Your own bureau login.') }}</h3>
                                <p>{{ __('Every action you take is recorded with your name.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form">
                            @if ($invitation === null)
                                <div class="login-head">
                                    <h2>{{ __('This invite link is no longer valid') }}</h2>
                                    <p>{{ __('It may have expired (links work for 72 hours), been used already or been cancelled. Ask the bureau owner to send a new one.') }}</p>
                                </div>
                                <p class="login-prompt"><a href="{{ route('login') }}" wire:navigate>{{ __('Go to login') }}</a></p>
                            @elseif ($step === 'password')
                                <div class="login-head">
                                    <h2>{{ __('Join :bureau', ['bureau' => $bureau]) }}</h2>
                                    <p>{{ __(':name, you were invited as :role. Choose a password; we then text a code to :phone.', [
                                        'name' => $invitation->name, 'role' => $invitation->role->label(), 'phone' => $maskedPhone,
                                    ]) }}</p>
                                </div>
                                <form wire:submit="start" novalidate>
                                    <div class="login-field">
                                        <label for="invitePassword" class="login-label">{{ __('Password') }}</label>
                                        <input id="invitePassword" name="password" type="password" autocomplete="new-password" maxlength="72" wire:model="password"
                                               @class(['form-control', 'is-invalid' => $errors->has('password')])
                                               @error('password') aria-invalid="true" aria-describedby="invitePassword-error" @enderror>
                                        @error('password') <p class="invalid-feedback d-block" id="invitePassword-error" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="login-field">
                                        <label for="invitePasswordConfirm" class="login-label">{{ __('Confirm password') }}</label>
                                        <input id="invitePasswordConfirm" name="password_confirmation" type="password" autocomplete="new-password" maxlength="72"
                                               wire:model="password_confirmation" class="form-control">
                                    </div>
                                    <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="start">
                                        <span class="ui-spinner" wire:loading wire:target="start" aria-hidden="true"></span>
                                        {{ __('Continue') }}
                                    </button>
                                </form>
                            @else
                                <div class="login-head">
                                    <h2>{{ __('Enter the code') }}</h2>
                                    <p>{{ __('We sent a 6-digit code by SMS to :phone.', ['phone' => $maskedPhone]) }}</p>
                                </div>
                                <form wire:submit="verify" novalidate>
                                    <div class="login-field">
                                        <label for="inviteCode" class="login-label">{{ __('6-digit code') }}</label>
                                        <input id="inviteCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" wire:model="code"
                                               @class(['form-control', 'is-invalid' => $errors->has('code')])
                                               @error('code') aria-invalid="true" aria-describedby="inviteCode-error" @enderror>
                                        @error('code') <p class="invalid-feedback d-block" id="inviteCode-error" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="verify">
                                        <span class="ui-spinner" wire:loading wire:target="verify" aria-hidden="true"></span>
                                        {{ __('Verify and join') }}
                                    </button>
                                    <p class="login-prompt">
                                        <button type="button" class="btn btn-link p-0" wire:click="back">{{ __('Didn’t get it? Go back and try again') }}</button>
                                    </p>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
