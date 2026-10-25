{{-- /register/claim — "Is this you?" (§11A B.6 claim flow). Same card as the login page (template login.php). --}}
<div>
    <h1 class="visually-hidden">{{ __('Is this your profile?') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h3>{{ __('Welcome to Oppam.') }}</h3>
                                <p>{{ __('A registered bureau already set up a profile with your number.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form claim-card">
                            <div class="login-head">
                                <h2>{{ __('Is this you?') }}</h2>
                                <p>{{ __('We found a profile for this number:') }}</p>
                            </div>

                            <p class="claim-card-profile">
                                <strong>{{ $name }}</strong>
                                <span class="claim-card-code">({{ $code }})</span>
                            </p>

                            <p class="claim-card-note">{{ __('If it is you, the profile becomes yours: you can chat, edit it and decide who sees what. The bureau will no longer have access to it.') }}</p>

                            <div class="claim-card-actions">
                                <button type="button" class="btn login-btn" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm,decline">
                                    <span class="ui-spinner" wire:loading wire:target="confirm" aria-hidden="true"></span>
                                    {{ __('Yes, this is me') }}
                                </button>
                                <button type="button" class="btn btn-outline-secondary claim-card-no" wire:click="decline" wire:loading.attr="disabled" wire:target="confirm,decline">
                                    <span class="ui-spinner" wire:loading wire:target="decline" aria-hidden="true"></span>
                                    {{ __('No, create my own profile') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
