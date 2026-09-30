{{-- /verify-otp — registration step 2 (M01). Same card as the login page (template login.php). --}}
<div>
    <h1 class="visually-hidden">{{ __('Verify your mobile number') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h3>{{ __('One quick check.') }}</h3>
                                <p>{{ __('Every Oppam profile has a verified mobile number.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form">
                            <div class="login-head">
                                <h2>{{ __('Enter the code') }}</h2>
                                <p>{{ __('We sent a 6-digit code by SMS to :phone. It is valid for a few minutes.', ['phone' => $maskedPhone]) }}</p>
                            </div>

                            @if (session('otp_notice'))
                                <x-ui.alert type="warning">{{ session('otp_notice') }}</x-ui.alert>
                            @endif
                            @if (session('otp_status'))
                                <x-ui.alert type="success">{{ session('otp_status') }}</x-ui.alert>
                            @endif

                            <form wire:submit="verify" novalidate>
                                @include('livewire.member.auth.partials.otp-code-field', ['resendAction' => 'resend'])

                                <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="verify">
                                    <span class="ui-spinner" wire:loading wire:target="verify" aria-hidden="true"></span>
                                    {{ __('Verify and continue') }}
                                </button>

                                <p class="login-prompt">{{ __('Wrong number?') }} <a href="{{ route('register') }}" wire:navigate>{{ __('Register again') }}</a></p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
