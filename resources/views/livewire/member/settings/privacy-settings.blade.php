{{--
    /settings → Privacy (M14). One form, one Save: each choice says what it changes. Incognito is a
    Diamond feature — shown to everyone, switchable only with the plan (the Action re-checks).
--}}
<div>
    <form wire:submit="save" id="privacyForm" novalidate>
        <div class="ui-card mb-4">
            <h2 class="safety-list__title">{{ __('Who sees my details') }}</h2>
            <div class="row g-3">
                <div class="col-md-4 col-12">
                    <x-ui.select :label="__('My photos')" name="photoVisibility" id="privacyPhoto" wire:model="photoVisibility"
                                 :options="$photoOptions" :selected="$photoVisibility" required />
                </div>
                <div class="col-md-4 col-12">
                    <x-ui.select :label="__('My phone number')" name="phoneVisibility" id="privacyPhone" wire:model="phoneVisibility"
                                 :options="$phoneOptions" :selected="$phoneVisibility" required />
                </div>
                <div class="col-md-4 col-12">
                    <x-ui.select :label="__('My horoscope')" name="horoscopeVisibility" id="privacyHoroscope" wire:model="horoscopeVisibility"
                                 :options="$horoscopeOptions" :selected="$horoscopeVisibility" required />
                </div>
            </div>
            <p class="form-text mt-2">{{ __('“On request” means members ask first and you decide. Members you have accepted an interest with count as accepted matches.') }}</p>
        </div>

        <div class="ui-card mb-4">
            <h2 class="safety-list__title">{{ __('Online and chat') }}</h2>
            <x-ui.checkbox name="showOnlineStatus" id="privacyOnline" wire:model="showOnlineStatus" dusk="privacy-online">
                {{ __('Show when I’m online (the green dot)') }}
            </x-ui.checkbox>
            <x-ui.checkbox name="showLastSeen" id="privacyLastSeen" wire:model="showLastSeen">
                {{ __('Show when I was last active') }}
            </x-ui.checkbox>
            <x-ui.checkbox name="readReceipts" id="privacyReadReceipts" wire:model="readReceipts">
                {{ __('Send read receipts in chat (switching this off also hides others’ receipts from you)') }}
            </x-ui.checkbox>
        </div>

        <div class="ui-card mb-4">
            <h2 class="safety-list__title">{{ __('Visibility and contact') }}</h2>
            <x-ui.checkbox name="hideFromSearch" id="privacyHide" wire:model="hideFromSearch" dusk="privacy-hide">
                {{ __('Hide me from search for now') }}
            </x-ui.checkbox>
            <p class="form-text">{{ __('You won’t appear in search, daily matches, alerts or on the home page. Members you already chat with can still open your profile and talk to you.') }}</p>

            <x-ui.checkbox name="incognito" id="privacyIncognito" wire:model="incognito" :disabled="! $incognitoAvailable && ! $incognito">
                {{ __('Browse incognito') }}
            </x-ui.checkbox>
            <p class="form-text">
                {{ __('Profiles you open won’t show you in their “who viewed me”.') }}
                @unless ($incognitoAvailable)
                    {{ __('Included with Diamond.') }} <a href="{{ route('plans') }}" wire:navigate>{{ __('See plans') }}</a>
                @endunless
            </p>

            <x-ui.checkbox name="contactFilter" id="privacyContactFilter" wire:model="contactFilter">
                {{ __('Only members who match my partner preferences can send me interests') }}
            </x-ui.checkbox>
            <p class="form-text">{{ __('Others still see your profile, but can’t send you an interest or see your phone number.') }}</p>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <x-ui.button type="submit" variant="primary" loading="save" dusk="privacy-save">{{ __('Save privacy settings') }}</x-ui.button>
        </div>
    </form>

    <div class="ui-card">
        <h2 class="safety-list__title">{{ __('Home page') }}</h2>
        <livewire:member.settings.home-visibility-toggle />
    </div>
</div>
