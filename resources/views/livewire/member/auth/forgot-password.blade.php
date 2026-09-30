{{-- /forgot-password (template forgot-password.php, PRD §8.2 method 3): the reset now goes by SMS
     code to the registered mobile; step 2 looks the same whether or not the number is registered. --}}
<div>
    <h1 class="visually-hidden">{{ __('Reset your password') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h2>{{ __('It happens to everyone.') }}</h2>
                                <p>{{ __('Enter your registered mobile number and we will text you a code to set a new password.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form">
                            <div class="login-head">
                                <h2>{{ __('Forgot password?') }}</h2>
                                <p>{{ $codeSent
                                    ? __('If :phone is registered, we have sent a 6-digit code to it.', ['phone' => $maskedPhone ?? ''])
                                    : __('Enter your registered mobile number.') }}</p>
                            </div>

                            @if (! $codeSent)
                                <form wire:submit="sendCode" novalidate wire:key="forgot-phone">
                                    <div class="login-field">
                                        <label for="resetMobile" class="login-label">{{ __('Mobile number') }}</label>
                                        <div class="row g-2">
                                            <div class="col-5">
                                                <select id="resetCountry" name="country_code" wire:model="countryCode" class="form-select login-input" aria-label="{{ __('Country code') }}">
                                                    @foreach ($countryOptions as $code => $label)
                                                        <option value="{{ $code }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-7">
                                                <input type="tel" id="resetMobile" name="mobile" wire:model="mobile" inputmode="tel" maxlength="20" autocomplete="tel-national" required
                                                       class="form-control login-input @error('mobile') is-invalid @enderror" placeholder="{{ __('Registered mobile number') }}"
                                                       @error('mobile') aria-invalid="true" aria-describedby="resetMobile-error" @enderror>
                                            </div>
                                        </div>
                                        @error('mobile')<p class="invalid-feedback d-block" id="resetMobile-error" role="alert">{{ $message }}</p>@enderror
                                    </div>

                                    <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="sendCode">
                                        <span class="ui-spinner" wire:loading wire:target="sendCode" aria-hidden="true"></span>
                                        {{ __('Send code') }}
                                    </button>
                                </form>
                            @else
                                <form wire:submit="resetPassword" novalidate wire:key="forgot-reset">
                                    @include('livewire.member.auth.partials.otp-code-field', ['resendAction' => 'sendCode'])

                                    <div class="login-field">
                                        <label for="newPassword" class="login-label">{{ __('New password') }}</label>
                                        <input type="password" id="newPassword" name="password" wire:model="password" maxlength="72" autocomplete="new-password" required
                                               class="form-control login-input @error('password') is-invalid @enderror" placeholder="{{ __('At least 8 characters, letters and numbers') }}"
                                               @error('password') aria-invalid="true" aria-describedby="newPassword-error" @enderror>
                                        @error('password')<p class="invalid-feedback d-block" id="newPassword-error">{{ $message }}</p>@enderror
                                    </div>

                                    <div class="login-field">
                                        <label for="newPasswordConfirm" class="login-label">{{ __('Confirm new password') }}</label>
                                        <input type="password" id="newPasswordConfirm" name="password_confirmation" wire:model="password_confirmation" maxlength="72" autocomplete="new-password" required
                                               class="form-control login-input">
                                    </div>

                                    <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="resetPassword">
                                        <span class="ui-spinner" wire:loading wire:target="resetPassword" aria-hidden="true"></span>
                                        {{ __('Set new password') }}
                                    </button>
                                    <button type="button" class="btn login-btn-alt" wire:click="changeNumber">{{ __('Use a different number') }}</button>
                                </form>
                            @endif

                            <p class="login-prompt">{{ __('Remembered it?') }} <a href="{{ route('login') }}" wire:navigate>{{ __('Back to login') }}</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
