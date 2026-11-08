{{-- /account/restore — "Your account is closed. Restore it?" (M14, P8.2c). Same card as the login page (template login.php). --}}
<div>
    <h1 class="visually-hidden">{{ __('Restore your account') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h3>{{ __('Welcome back.') }}</h3>
                                <p>{{ __('Your profile is waiting just as you left it.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form claim-card">
                            <div class="login-head">
                                <h2>{{ __('Your account is closed') }}</h2>
                                @if ($closedOn)
                                    <p>{{ __('You closed it on :date.', ['date' => $closedOn->format('j M Y')]) }}</p>
                                @endif
                            </div>

                            <p class="claim-card-note">
                                {{ __('You can restore it now: your profile, photos, matches and conversations come back, and any paid plan carries on.') }}
                                @if ($restoreUntil)
                                    {{ __('After :date it will be erased for good.', ['date' => $restoreUntil->format('j M Y')]) }}
                                @endif
                            </p>

                            <div class="claim-card-actions">
                                <button type="button" class="btn login-btn" wire:click="restore" wire:loading.attr="disabled" wire:target="restore,notNow">
                                    <span class="ui-spinner" wire:loading wire:target="restore" aria-hidden="true"></span>
                                    {{ __('Restore my account') }}
                                </button>
                                <button type="button" class="btn btn-outline-secondary claim-card-no" wire:click="notNow" wire:loading.attr="disabled" wire:target="restore,notNow">
                                    <span class="ui-spinner" wire:loading wire:target="notNow" aria-hidden="true"></span>
                                    {{ __('Not now') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
