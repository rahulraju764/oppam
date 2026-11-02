{{-- The member's opt-in to the public home page (owner 2026-11-01). Off by default; explains who sees it. --}}
<div class="home-visibility">
    <x-ui.checkbox name="showOnHome" id="show-on-home" wire:model.live="show" dusk="show-on-home">{{ __('Show me on the home page') }}</x-ui.checkbox>
    <p class="form-text">
        {{ __('Anyone visiting Oppam, signed in or not, may see your photo, first name and district on the home page. Your profile and contact details stay private.') }}
    </p>
    @if (! $eligible)
        <p class="form-text">{{ __('You’ll only appear while you have a paid plan, a verified ID, and your photos are visible to all members.') }}</p>
    @endif
</div>
