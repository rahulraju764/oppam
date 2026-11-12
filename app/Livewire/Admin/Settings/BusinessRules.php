<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Actions\Admin\Settings\UpdateSettings;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\AdminUser;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A15 business rules (P8.4): EVERY non-site setting, grouped by section, generated from the
 * SettingKey enum (label, unit, rules) — nothing listed here by hand, so a new key appears on its
 * own. Viewing needs settings.view; a change needs settings.edit, is validated against the key's
 * own rules, takes a reason and is audited (UpdateSettings). It applies on the next read, without
 * a deploy.
 */
#[Layout('layouts::admin', ['title' => 'Business rules'])]
final class BusinessRules extends Component
{
    #[Url(except: '')]
    public string $q = '';

    /** The setting being edited (a SettingKey value), set server-side by edit(). */
    #[Locked]
    public ?string $editing = null;

    public string $value = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function edit(string $key): void
    {
        $this->authorize('settings.edit');
        $setting = $this->rule($key);

        $this->resetValidation();
        $this->editing = $setting->value;
        $this->value = (string) Settings::get($setting);
        $this->reason = '';
        $this->dispatch('open-modal', name: 'rule-edit');
    }

    public function save(UpdateSettings $update): void
    {
        $setting = $this->rule((string) $this->editing);

        try {
            $update->handle($this->admin(), [$setting->value => $this->value], $this->reason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The Action reports errors under the setting key; the form field is `value`.
            $errors = $e->errors();
            $this->setErrorBag(array_filter([
                'value' => $errors[$setting->value] ?? null,
                'reason' => $errors['reason'] ?? null,
            ]));

            return;
        }

        $this->editing = null;
        $this->dispatch('close-modal', name: 'rule-edit');
        $this->dispatch('toast', type: 'success', message: __(':rule saved. It applies from now on.', ['rule' => $setting->label()]));
    }

    public function render(): View
    {
        $needle = mb_strtolower(trim($this->q));
        $sections = collect(SettingKey::cases())
            ->reject(fn (SettingKey $k): bool => $k->isSite())
            ->filter(fn (SettingKey $k): bool => $needle === '' || str_contains(mb_strtolower($k->label().' '.$k->value.' '.$k->section()), $needle))
            ->groupBy(fn (SettingKey $k): string => $k->section());

        $editingKey = SettingKey::tryFrom((string) $this->editing);

        return view('livewire.admin.settings.business-rules', [
            'sections' => $sections,
            'values' => collect(SettingKey::cases())->mapWithKeys(fn (SettingKey $k): array => [$k->value => Settings::get($k)])->all(),
            'canEdit' => Gate::forUser($this->admin())->allows('settings.edit'),
            'editingKey' => $editingKey,
            'limits' => $editingKey === null ? null : self::limits($editingKey),
        ]);
    }

    /** "1 – 365" from the key's own min / max rules, for the edit dialog. */
    public static function limits(SettingKey $key): ?string
    {
        $min = $max = null;
        foreach ($key->rules() as $rule) {
            if (str_starts_with($rule, 'min:')) {
                $min = substr($rule, 4);
            } elseif (str_starts_with($rule, 'max:')) {
                $max = substr($rule, 4);
            }
        }

        return $key->type() === SettingType::Integer && $min !== null && $max !== null ? number_format((int) $min).' – '.number_format((int) $max) : null;
    }

    private function rule(string $key): SettingKey
    {
        $setting = SettingKey::tryFrom($key);
        abort_if($setting === null || $setting->isSite(), 404);

        return $setting;
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
