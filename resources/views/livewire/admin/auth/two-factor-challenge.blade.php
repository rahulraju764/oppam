<div>
    <h1>{{ __('Two-factor authentication') }}</h1>
    <p class="admin-auth__lead">
        {{ $useRecoveryCode
            ? __('Enter one of your recovery codes. Each code works once.')
            : __('Enter the 6-digit code from your authenticator app.') }}
    </p>

    <form wire:submit="verify" novalidate>
        @if ($useRecoveryCode)
            <x-ui.input :label="__('Recovery code')" name="code" wire:model="code" autocomplete="one-time-code" required autofocus />
        @else
            <x-ui.input :label="__('Authentication code')" name="code" wire:model="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus />
        @endif

        <x-ui.button class="w-100 mt-4" loading="verify">{{ __('Verify and sign in') }}</x-ui.button>
    </form>

    <p class="text-center mt-3 mb-0">
        <x-ui.button variant="link" type="button" wire:click="toggleRecovery">
            {{ $useRecoveryCode ? __('Use the authenticator app instead') : __('Lost your device? Use a recovery code') }}
        </x-ui.button>
    </p>
</div>
