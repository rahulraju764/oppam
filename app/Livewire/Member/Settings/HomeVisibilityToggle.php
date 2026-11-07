<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Actions\Profile\SetHomeVisibility;
use App\Enums\PhotoVisibility;
use App\Exceptions\Admin\ContentRefused;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * <livewire:member.settings.home-visibility-toggle /> in the /settings Privacy tab (moved from My
 * Profile in P8.2a): the member's opt-in to the public home page (owner 2026-11-01). Off by default.
 */
final class HomeVisibilityToggle extends Component
{
    public bool $show = false;

    public function mount(): void
    {
        $this->show = $this->member()->profile?->privacySetting?->show_on_home === true;
    }

    public function updatedShow(SetHomeVisibility $set): void
    {
        try {
            $set->handle($this->member(), $this->show);
        } catch (ImpersonationRestricted|ContentRefused $restricted) {
            $this->show = ! $this->show;
            $this->dispatch('toast', type: 'danger', title: $restricted->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: $this->show ? __('You can now appear on the home page.') : __('You won’t appear on the home page.'));
    }

    public function render(): View
    {
        $profile = $this->member()->profile;
        $eligible = $profile?->is_premium === true && $profile->is_verified === true
            && ($profile->privacySetting->photo_visibility ?? PhotoVisibility::AllMembers) === PhotoVisibility::AllMembers
            && $profile->privacySetting?->hide_from_search !== true;

        return view('livewire.member.settings.home-visibility-toggle', ['eligible' => $eligible]);
    }

    private function member(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 404);

        return $user;
    }
}
