{{--
    /settings → Account (M14 / M01): mobile number (two codes), email (a link), password, recent
    sign-ins. Each card is its own small form; refusals appear under the field they concern.
--}}
@php($stepNew = $progress->oldConfirmed)
<div>
    {{-- MOBILE --------------------------------------------------------------------------------- --}}
    <div class="ui-card mb-4">
        <h2 class="safety-list__title">{{ __('Mobile number') }}</h2>
        <p class="mb-2"><strong>{{ $phoneMasked }}</strong> <span class="text-muted-brand">· {{ __('you sign in with this number') }}</span></p>

        @if (! $phoneOpen && ! $stepNew)
            <x-ui.button variant="outline" size="sm" type="button" wire:click="startPhoneChange" loading="startPhoneChange" dusk="phone-start">
                {{ __('Change mobile number') }}
            </x-ui.button>
            @error('oldCode')<p class="invalid-feedback d-block" role="alert">{{ $message }}</p>@enderror
        @elseif (! $stepNew)
            <form wire:submit="confirmOldCode" novalidate>
                <p class="form-text">{{ __('Step 1 of 2: enter the code we sent to :phone.', ['phone' => $phoneMasked]) }}</p>
                <x-ui.input :label="__('Code sent to your current number')" name="oldCode" id="accountOldCode" wire:model="oldCode"
                            inputmode="numeric" maxlength="6" autocomplete="one-time-code" dusk="phone-old-code" required />
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <x-ui.button type="submit" size="sm" loading="confirmOldCode" dusk="phone-old-confirm">{{ __('Confirm') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" type="button" wire:click="cancelPhoneChange" loading="cancelPhoneChange">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
            <p class="form-text mt-2">{{ __('Lost your old number?') }} <a href="{{ route('member.help') }}" wire:navigate>{{ __('Ask our support team') }}</a></p>
        @elseif ($progress->newNumberMasked === null)
            <form wire:submit="sendNewCode" novalidate>
                <p class="form-text">{{ __('Step 2 of 2: your new mobile number.') }}</p>
                <div class="row g-2">
                    <div class="col-4 col-sm-3">
                        <x-ui.select :label="__('Country')" name="newCountry" id="accountNewCountry" wire:model="newCountry" :options="$countryOptions" :selected="$newCountry" />
                    </div>
                    <div class="col-8 col-sm-9">
                        <x-ui.input :label="__('New mobile number')" name="newMobile" id="accountNewMobile" type="tel" wire:model="newMobile"
                                    inputmode="tel" maxlength="20" autocomplete="tel-national" dusk="phone-new-number" required />
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <x-ui.button type="submit" size="sm" loading="sendNewCode" dusk="phone-new-send">{{ __('Send code') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" type="button" wire:click="cancelPhoneChange" loading="cancelPhoneChange">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        @else
            <form wire:submit="confirmNewCode" novalidate>
                <p class="form-text">{{ __('Enter the code we sent to :phone.', ['phone' => $progress->newNumberMasked]) }}</p>
                <x-ui.input :label="__('Code sent to your new number')" name="newCode" id="accountNewCode" wire:model="newCode"
                            inputmode="numeric" maxlength="6" autocomplete="one-time-code" dusk="phone-new-code" required />
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <x-ui.button type="submit" size="sm" loading="confirmNewCode" dusk="phone-new-confirm">{{ __('Change my number') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" type="button" wire:click="cancelPhoneChange" loading="cancelPhoneChange">{{ __('Cancel') }}</x-ui.button>
                </div>
                <p class="form-text mt-2">{{ __('Changing your number signs you out on every other device.') }}</p>
            </form>
        @endif
    </div>

    {{-- EMAIL ---------------------------------------------------------------------------------- --}}
    <div class="ui-card mb-4">
        <h2 class="safety-list__title">{{ __('Email address') }}</h2>
        @if ($currentEmail !== null)
            <p class="mb-2">
                <strong>{{ $currentEmail }}</strong>
                @if ($emailVerified)
                    <x-ui.badge variant="success">{{ __('Verified') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="warning">{{ __('Not verified') }}</x-ui.badge>
                @endif
            </p>
        @else
            <p class="form-text">{{ __('No email address yet. Add one to get account emails and to reset your password by email.') }}</p>
        @endif
        @unless ($emailVerified)
            <p class="form-text">{{ __('We only send email to an address you have confirmed.') }}</p>
        @endunless

        <form wire:submit="sendEmailLink" novalidate>
            <x-ui.input :label="$currentEmail === null ? __('Email address') : __('New or same email address')" name="email" id="accountEmail" type="email"
                        wire:model="email" autocomplete="email" maxlength="255" dusk="email-input" required />
            <div class="d-flex flex-wrap gap-2 mt-2">
                <x-ui.button type="submit" size="sm" loading="sendEmailLink" dusk="email-send">{{ __('Send confirmation link') }}</x-ui.button>
                @if ($currentEmail !== null)
                    <x-ui.button variant="ghost" size="sm" type="button" x-on:click="$dispatch('open-modal', { name: 'remove-email' })">{{ __('Remove email') }}</x-ui.button>
                @endif
            </div>
        </form>

        <x-ui.modal name="remove-email" :title="__('Remove your email address?')">
            <p>{{ __('We won’t send you any email after this — not even security alerts. You keep signing in with your mobile number.') }}</p>
            <x-slot:footer>
                <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Keep it') }}</x-ui.button>
                <x-ui.button variant="danger" type="button" wire:click="removeEmail" loading="removeEmail">{{ __('Remove') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>

    {{-- PASSWORD ------------------------------------------------------------------------------- --}}
    <div class="ui-card mb-4">
        <h2 class="safety-list__title">{{ __('Password') }}</h2>
        <form wire:submit="changePassword" novalidate>
            <x-ui.input :label="__('Current password')" name="currentPassword" id="accountCurrentPassword" type="password"
                        wire:model="currentPassword" autocomplete="current-password" maxlength="72" dusk="password-current" required />
            <div class="row g-3 mt-0">
                <div class="col-md-6 col-12">
                    <x-ui.input :label="__('New password')" name="password" id="accountNewPassword" type="password" wire:model="password"
                                autocomplete="new-password" maxlength="72" :hint="__('At least 8 characters, with letters and numbers.')" dusk="password-new" required />
                </div>
                <div class="col-md-6 col-12">
                    <x-ui.input :label="__('Repeat the new password')" name="password_confirmation" id="accountNewPasswordConfirm" type="password"
                                wire:model="password_confirmation" autocomplete="new-password" maxlength="72" dusk="password-confirm" required />
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <x-ui.button type="submit" size="sm" loading="changePassword" dusk="password-save">{{ __('Change password') }}</x-ui.button>
                <span class="form-text m-0">{{ __('Changing it signs you out on every other device.') }}</span>
            </div>
        </form>
    </div>

    {{-- DEVICES -------------------------------------------------------------------------------- --}}
    <div class="ui-card">
        <h2 class="safety-list__title">{{ __('Recent sign-ins') }}</h2>
        <p class="form-text">{{ __('The devices that signed in to your account in the last 90 days. Don’t recognise one? Change your password and sign out all other devices.') }}</p>
        @if ($devices === [])
            <x-ui.empty-state icon="fa-laptop" :title="__('No sign-ins to show')" :message="__('Sign-ins from the last 90 days appear here.')" />
        @else
            <ul class="safety-list">
                @foreach ($devices as $i => $device)
                    <li class="safety-list__row" wire:key="device-{{ $i }}">
                        <span class="safety-list__who">
                            <strong>{{ $device->label }}</strong>
                            @if ($device->isThisDevice)<x-ui.badge variant="verified">{{ __('This device') }}</x-ui.badge>@endif
                            <span class="text-muted-brand">{{ __('IP :ip · :when', ['ip' => $device->ipMasked, 'when' => $device->lastSignedInAt->timezone($timezone)->format('j M Y, g:i A')]) }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        @error('devices')<p class="invalid-feedback d-block" role="alert">{{ $message }}</p>@enderror
        <x-ui.button variant="outline" size="sm" type="button" class="mt-3" wire:click="signOutOtherDevices" loading="signOutOtherDevices" dusk="devices-signout">
            {{ __('Sign out all other devices') }}
        </x-ui.button>
    </div>
</div>
