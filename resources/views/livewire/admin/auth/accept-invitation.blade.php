<div>
    @if ($email === null)
        <h1>{{ __('Invitation not valid') }}</h1>
        <x-ui.empty-state icon="fa-envelope-o" :title="__('This link can’t be used')"
                          :message="__('It has expired, was already used, or was replaced by a newer invitation. Ask a super admin to invite you again.')" />
    @else
        <h1>{{ __('Welcome, :name', ['name' => $name]) }}</h1>
        <p class="admin-auth__lead">{{ __('Choose a password for :email. Next you will set up an authenticator app.', ['email' => $email]) }}</p>

        <form wire:submit="accept" novalidate>
            <x-ui.input :label="__('Password')" name="password" type="password" wire:model="password" autocomplete="new-password"
                        :hint="__('At least 12 characters, with upper and lower case letters and a number.')" required />
            <x-ui.input :label="__('Confirm password')" name="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" required />
            <x-ui.button class="w-100 mt-4" loading="accept">{{ __('Create my account') }}</x-ui.button>
        </form>
    @endif
</div>
