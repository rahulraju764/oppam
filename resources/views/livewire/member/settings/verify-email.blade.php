{{-- /settings/email/verify/{token} (M14, P8.2b): confirm an email address from its link. --}}
<div>
    <section class="login-section">
        <div class="container is-readable">
            <div class="ui-card text-center">
                <h1 class="h3">{{ __('Confirm your email address') }}</h1>

                @if ($done)
                    <x-ui.alert type="success">{{ __('Thank you — your email address is confirmed.') }}</x-ui.alert>
                    <a href="{{ $signedIn ? route('member.settings', ['tab' => 'account']) : route('login') }}" class="btn login-btn mt-3" wire:navigate>
                        {{ $signedIn ? __('Back to your settings') : __('Sign in') }}
                    </a>
                @else
                    <p>{{ __('Confirm that this email address belongs to your Oppam Matrimony account.') }}</p>
                    @if ($error !== null)
                        <x-ui.alert type="danger">{{ $error }}</x-ui.alert>
                    @endif
                    <x-ui.button type="button" wire:click="confirm" loading="confirm" dusk="email-confirm">{{ __('Confirm my email address') }}</x-ui.button>
                @endif
            </div>
        </div>
    </section>
</div>
