{{--
    /settings (M14): tabs switched in place (`?tab=`, wire:click — no reload). Each panel is a lazy
    component; a skeleton shows while it loads and while the tab changes.
--}}
<div>
    <section class="daily-section bg-coloring">
        <div class="container is-readable">
            <div class="d-head daily-head">
                <h1>{{ __('Settings') }}</h1>
                <p class="daily-head__expiry">{{ __('Choose who sees what, and how we tell you about things.') }}</p>
            </div>

            {{-- Flashed by "Block" / "Ignore" on a profile (read here: the lazy tab loads in a later request). --}}
            @if (session('status'))
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            @endif

            <div class="visitor-tabs" role="group" aria-label="{{ __('Settings sections') }}">
                @foreach ($tabs as $key => $label)
                    <button type="button" class="visitor-tab" wire:click="show('{{ $key }}')" dusk="settings-tab-{{ $key }}"
                            aria-pressed="{{ $tab === $key ? 'true' : 'false' }}" aria-controls="settings-panel">
                        {{ __($label) }}
                    </button>
                @endforeach
            </div>

            <div wire:loading.block wire:target="show" aria-hidden="true">
                <x-ui.skeleton shape="card" :count="2" />
            </div>

            <div id="settings-panel" wire:loading.remove wire:target="show">
                @switch ($tab)
                    @case('notifications')
                        <livewire:member.settings.notification-settings lazy wire:key="settings-notifications" />
                        @break
                    @case('blocked')
                        <livewire:member.settings.blocked-profiles lazy wire:key="settings-blocked" />
                        @break
                    @default
                        <livewire:member.settings.privacy-settings lazy wire:key="settings-privacy" />
                @endswitch
            </div>
        </div>
    </section>
</div>
