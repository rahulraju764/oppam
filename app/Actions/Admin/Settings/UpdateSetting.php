<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Change one admin-editable setting (PRD A15: "editable, validated, audited"). The key comes
 * from the SettingKey enum (never a free-form string), the value is validated against the key's
 * own rules, and the change is audited with before/after and a required reason. Needs settings.edit.
 */
final class UpdateSetting
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $actor, SettingKey $key, mixed $value, string $reason): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        Validator::make(
            ['value' => $value, 'reason' => $reason],
            ['value' => $key->rules(), 'reason' => ['required', 'string', 'max:500']],
        )->validate();

        $after = $key->type()->cast($value);

        DB::transaction(function () use ($actor, $key, $after, $reason): void {
            // "before" from the locked row, not the cache: concurrent edits audit the true sequence.
            $setting = Setting::query()->lockForUpdate()->find($key->value);
            $before = $setting !== null ? $key->type()->cast($setting->value) : $key->default();
            $setting ??= (new Setting)->forceFill(['key' => $key->value]);
            $setting->forceFill(['value' => $after, 'updated_by_id' => $actor->id])->save();

            // Keys are longer than a ULID subject id: the key is the subject label.
            $this->audit->record('settings.updated', null, [$key->value => $before], [$key->value => $after], $reason, $actor, $key->value);
        }, attempts: 3);   // two first-ever edits of a key (gap locks) deadlock; the loser retries

        // After commit (also when called inside an outer transaction), so no reader re-caches the old value.
        DB::afterCommit(fn () => $this->settings->flush());
    }
}
