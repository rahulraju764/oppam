<div>
    <h1>{{ __('Admin sign in') }}</h1>
    <p class="admin-auth__lead">{{ __('Staff only. You will need your authenticator app.') }}</p>

    @if (session('status'))
        <x-ui.alert type="info" class="mb-3">{{ session('status') }}</x-ui.alert>
    @endif

    <form wire:submit="login" novalidate>
        <x-ui.input :label="__('Email')" name="email" type="email" wire:model="email" autocomplete="username" required autofocus />
        <x-ui.input :label="__('Password')" name="password" type="password" wire:model="password" autocomplete="current-password" required />

        <x-ui.button class="w-100 mt-4" loading="login">{{ __('Continue') }}</x-ui.button>
    </form>
</div>
