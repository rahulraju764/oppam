<div>
    @if ($recoveryCodes === [])
        <h1>{{ __('Set up two-factor authentication') }}</h1>
        <p class="admin-auth__lead">{{ __('Every admin account needs an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…).') }}</p>

        <ol class="ps-3">
            <li>{{ __('Scan this QR code with your authenticator app.') }}</li>
        </ol>
        <img src="{{ $qrCodeDataUri }}" alt="{{ __('QR code for your authenticator app') }}" class="admin-qr" width="192" height="192">

        <p class="mb-1">{{ __('Can’t scan it? Enter this key instead:') }}</p>
        <p class="mono-key">{{ $manualKey }}</p>

        <ol class="ps-3" start="2">
            <li>{{ __('Enter the 6-digit code the app shows.') }}</li>
        </ol>

        <form wire:submit="confirm" novalidate>
            <x-ui.input :label="__('Authentication code')" name="code" wire:model="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required />
            <x-ui.button class="w-100 mt-4" loading="confirm">{{ __('Confirm') }}</x-ui.button>
        </form>
    @else
        <h1>{{ __('Save your recovery codes') }}</h1>
        <x-ui.alert type="warning" :title="__('Shown only once')">
            {{ __('If you lose your phone, each of these codes lets you sign in once. Store them in a password manager — not in email or on this computer’s desktop.') }}
        </x-ui.alert>

        <ul class="recovery-codes" aria-label="{{ __('Recovery codes') }}">
            @foreach ($recoveryCodes as $recoveryCode)
                <li>{{ $recoveryCode }}</li>
            @endforeach
        </ul>

        <x-ui.button class="w-100" type="button" wire:click="finish" loading="finish">{{ __('I have saved them — continue') }}</x-ui.button>
    @endif
</div>
