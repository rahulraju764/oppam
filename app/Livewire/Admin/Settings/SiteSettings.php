<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Actions\Admin\Settings\UpdateSettings;
use App\Actions\Admin\Settings\UploadSiteLogo;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\AdminUser;
use App\Services\Settings\SiteIdentity;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A15 site settings (P8.4): site name, logo, social links, SEO defaults, the indexable switch,
 * contact details, maintenance message and the home-page band — every `isSite()` key of the
 * SettingKey enum (the logo through UploadSiteLogo). One Save = one transaction with one reason;
 * only fields that changed are written and audited (UpdateSettings). settings.view to look,
 * settings.edit to change.
 */
#[Layout('layouts::admin', ['title' => 'Site settings'])]
final class SiteSettings extends Component
{
    use WithFileUploads;

    /** @var array<string, mixed> SettingKey value (dots → "__") => form value */
    public array $form = [];

    public string $reason = '';

    public ?TemporaryUploadedFile $logo = null;

    public string $logoReason = '';

    public function mount(): void
    {
        $this->authorize('settings.view');
        foreach (self::keys() as $key) {
            $this->form[self::field($key)] = $key->type() === SettingType::Boolean ? (bool) Settings::get($key) : (string) Settings::get($key);
        }
    }

    /** A temporary upload is refused before it lands for an admin who can't change settings. */
    public function updatingLogo(): void
    {
        $this->authorize('settings.edit');
    }

    public function save(UpdateSettings $update): void
    {
        $values = [];
        foreach (self::keys() as $key) {
            $values[$key->value] = $this->form[self::field($key)] ?? null;
        }

        try {
            $changed = $update->handle($this->admin(), $values, $this->reason);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[$field === 'reason' ? 'reason' : 'form.'.str_replace('.', '__', $field)] = $messages;
            }
            $this->setErrorBag($errors);

            return;
        }

        $this->reason = '';
        $this->dispatch('close-modal', name: 'site-save');
        $this->dispatch('toast', type: 'success', message: $changed === []
            ? __('Nothing changed.')
            : trans_choice(':count setting saved.|:count settings saved.', count($changed)));
    }

    public function uploadLogo(UploadSiteLogo $upload): void
    {
        $file = $this->logo instanceof TemporaryUploadedFile ? $this->logo
            : throw ValidationException::withMessages(['logo' => __('Please choose a PNG or WebP file.')]);
        $upload->handle($this->admin(), $file, $this->logoReason);

        $this->reset('logo', 'logoReason');
        $this->dispatch('close-modal', name: 'logo-upload');
        $this->dispatch('toast', type: 'success', message: __('Logo updated.'));
    }

    public function removeLogo(UploadSiteLogo $upload): void
    {
        $upload->remove($this->admin(), $this->logoReason);

        $this->reset('logoReason');
        $this->dispatch('close-modal', name: 'logo-upload');
        $this->dispatch('toast', type: 'success', message: __('The bundled logo is back.'));
    }

    public function render(SiteIdentity $identity): View
    {
        return view('livewire.admin.settings.site-settings', [
            'keys' => self::keys(),
            'canEdit' => Gate::forUser($this->admin())->allows('settings.edit'),
            'logoUrl' => $identity->logoUrl(),
            'hasCustomLogo' => Settings::string(SettingKey::SiteLogoPath) !== '',
        ]);
    }

    /** @return list<SettingKey> the site keys edited in the form (the logo has its own dialog) */
    public static function keys(): array
    {
        return array_values(array_filter(SettingKey::cases(), fn (SettingKey $k): bool => $k->isSite() && $k !== SettingKey::SiteLogoPath));
    }

    /** Livewire reads dots in a property path as nesting: "site.name" is stored under "site__name". */
    public static function field(SettingKey $key): string
    {
        return str_replace('.', '__', $key->value);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
