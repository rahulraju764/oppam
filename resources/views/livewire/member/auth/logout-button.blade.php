{{-- Member header menu: Logout + Log out other devices (M01). Both POST through Livewire. --}}
<div>
    <button type="button" class="profile-logout" wire:click="logoutOtherDevices" wire:loading.attr="disabled" wire:target="logoutOtherDevices">
        <i class="fa fa-mobile" aria-hidden="true"></i>
        {{ __('Log out other devices') }}
    </button>
    @if ($notice)
        <p class="form-text" role="status">{{ $notice }}</p>
    @endif
    <button type="button" class="profile-logout" wire:click="logout" wire:loading.attr="disabled" wire:target="logout">
        <i class="fa fa-sign-out" aria-hidden="true"></i>
        {{ __('Logout') }}
    </button>
</div>
