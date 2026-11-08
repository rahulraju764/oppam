<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Actions\Admin\Settings\UpdateSetting;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Site settings configuration screen (PRD §11 A15).
 * Allows admins holding settings.edit to update contact info, SEO, and maintenance mode.
 */
#[Layout('layouts::admin', ['title' => 'Site Settings'])]
final class SiteSettings extends Component
{
    public string $supportEmail = '';

    public string $infoEmail = '';

    public string $phoneDisplay = '';

    public string $phoneDial = '';

    public string $address = '';

    public string $hours = '';

    public string $maintenanceMessage = '';

    public bool $seoIndexable = false;

    public int $homeMembersMin = 4;

    public int $homeToggleMaxPerHour = 5;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('settings.view');

        $this->supportEmail = Settings::string(SettingKey::SiteSupportEmail);
        $this->infoEmail = Settings::string(SettingKey::SiteInfoEmail);
        $this->phoneDisplay = Settings::string(SettingKey::SitePhoneDisplay);
        $this->phoneDial = Settings::string(SettingKey::SitePhoneDial);
        $this->address = Settings::string(SettingKey::SiteAddress);
        $this->hours = Settings::string(SettingKey::SiteHours);
        $this->maintenanceMessage = Settings::string(SettingKey::SiteMaintenanceMessage);
        $this->seoIndexable = Settings::bool(SettingKey::SeoIndexable);
        $this->homeMembersMin = Settings::int(SettingKey::ContentHomeMembersMin);
        $this->homeToggleMaxPerHour = Settings::int(SettingKey::ContentHomeToggleMaxPerHour);
    }

    public function save(UpdateSetting $action): void
    {
        $this->authorize('settings.edit');

        $this->validate([
            'supportEmail' => SettingKey::SiteSupportEmail->rules(),
            'infoEmail' => SettingKey::SiteInfoEmail->rules(),
            'phoneDisplay' => SettingKey::SitePhoneDisplay->rules(),
            'phoneDial' => SettingKey::SitePhoneDial->rules(),
            'address' => SettingKey::SiteAddress->rules(),
            'hours' => SettingKey::SiteHours->rules(),
            'maintenanceMessage' => SettingKey::SiteMaintenanceMessage->rules(),
            'seoIndexable' => SettingKey::SeoIndexable->rules(),
            'homeMembersMin' => SettingKey::ContentHomeMembersMin->rules(),
            'homeToggleMaxPerHour' => SettingKey::ContentHomeToggleMaxPerHour->rules(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $admin = $this->admin();

        $action->handle($admin, SettingKey::SiteSupportEmail, $this->supportEmail, $this->reason);
        $action->handle($admin, SettingKey::SiteInfoEmail, $this->infoEmail, $this->reason);
        $action->handle($admin, SettingKey::SitePhoneDisplay, $this->phoneDisplay, $this->reason);
        $action->handle($admin, SettingKey::SitePhoneDial, $this->phoneDial, $this->reason);
        $action->handle($admin, SettingKey::SiteAddress, $this->address, $this->reason);
        $action->handle($admin, SettingKey::SiteHours, $this->hours, $this->reason);
        $action->handle($admin, SettingKey::SiteMaintenanceMessage, $this->maintenanceMessage, $this->reason);
        $action->handle($admin, SettingKey::SeoIndexable, $this->seoIndexable, $this->reason);
        $action->handle($admin, SettingKey::ContentHomeMembersMin, $this->homeMembersMin, $this->reason);
        $action->handle($admin, SettingKey::ContentHomeToggleMaxPerHour, $this->homeToggleMaxPerHour, $this->reason);

        $this->reset('reason');
        session()->flash('status', __('Site settings updated successfully.'));
    }

    public function render(): View
    {
        return view('livewire.admin.settings.site-settings', [
            'brandName' => config('oppam.site.name', 'Oppam Matrimony'),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
