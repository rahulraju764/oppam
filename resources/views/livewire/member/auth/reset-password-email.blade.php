{{-- /reset-password/{token} (PRD §8.2 method 3, P8.2b): a new password from an email link. Same
     card as /forgot-password; the link is checked only when the form is sent. --}}
<div>
    <h1 class="visually-hidden">{{ __('Choose a new password') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h2>{{ __('Almost there.') }}</h2>
                                <p>{{ __('Choose a new password. Every other device will be signed out.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form">
                            <div class="login-head">
                                <h2>{{ __('Choose a new password') }}</h2>
                                <p>{{ __('At least 8 characters, with letters and numbers.') }}</p>
                            </div>

                            <form wire:submit="resetPassword" novalidate>
                                <div class="login-field">
                                    <label for="emailNewPassword" class="login-label">{{ __('New password') }}</label>
                                    <input type="password" id="emailNewPassword" name="password" wire:model="password" maxlength="72" autocomplete="new-password" required dusk="reset-new-password"
                                           class="form-control login-input @error('password') is-invalid @enderror"
                                           @error('password') aria-invalid="true" aria-describedby="emailNewPassword-error" @enderror>
                                    @error('password')<p class="invalid-feedback d-block" id="emailNewPassword-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div class="login-field">
                                    <label for="emailNewPasswordConfirm" class="login-label">{{ __('Confirm new password') }}</label>
                                    <input type="password" id="emailNewPasswordConfirm" name="password_confirmation" wire:model="password_confirmation" maxlength="72" autocomplete="new-password" required dusk="reset-confirm-password"
                                           class="form-control login-input">
                                </div>
                                <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="resetPassword" dusk="reset-save">
                                    <span class="ui-spinner" wire:loading wire:target="resetPassword" aria-hidden="true"></span>
                                    {{ __('Set new password') }}
                                </button>
                            </form>

                            <p class="login-prompt">{{ __('Link not working?') }} <a href="{{ route('password.forgot') }}" wire:navigate>{{ __('Ask for a new one') }}</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
