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
use Illuminate\Validation\ValidationException;

/**
 * Change several admin-editable settings at once (PRD A15 "editable, validated, audited"; P8.4):
 * one form save is ONE transaction — all keys or none. Keys come from the SettingKey enum (never
 * free-form), each value is validated against its key's own rules (errors keyed by the setting
 * key), and only keys whose value really changes are written and audited (one before / after row
 * each, with the reason). Takes effect on the next read — no deploy: the cache is flushed after
 * commit. Needs settings.edit.
 */
final class UpdateSettings
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  SettingKey value => new value
     * @return list<string> the keys that changed
     *
     * @throws ValidationException
     */
    public function handle(AdminUser $actor, array $values, string $reason): array
    {
        Gate::forUser($actor)->authorize('settings.edit');

        $keys = [];
        $rules = ['reason' => ['required', 'string', 'max:500']];
        foreach (array_keys($values) as $value) {
            $key = SettingKey::tryFrom((string) $value)
                ?? throw ValidationException::withMessages(['settings' => __('Unknown setting.')]);
            $keys[] = $key;
            $rules[$key->value] = $key->rules();
        }

        // Validator reads dots as nesting: validate under safe names, report under the setting key.
        $safe = fn (string $k): string => str_replace('.', '__', $k);
        $data = ['reason' => trim($reason)];
        $safeRules = ['reason' => $rules['reason']];
        foreach ($keys as $key) {
            $data[$safe($key->value)] = $values[$key->value];
            $safeRules[$safe($key->value)] = $rules[$key->value];
        }
        $validator = Validator::make($data, $safeRules, [], array_combine(
            array_map(fn (SettingKey $k): string => $safe($k->value), $keys),
            array_map(fn (SettingKey $k): string => mb_strtolower($k->label()), $keys),
        ) ?: []);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[str_replace('__', '.', $field)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        $changed = DB::transaction(function () use ($actor, $keys, $values, $reason): array {
            $changed = [];
            foreach ($keys as $key) {
                $after = $key->type()->cast($values[$key->value] ?? '');
                // "before" from the locked row, not the cache: concurrent edits audit the true sequence.
                $setting = Setting::query()->lockForUpdate()->find($key->value);
                $before = $setting !== null ? $key->type()->cast($setting->value) : $key->default();
                if ($before === $after) {
                    continue;
                }

                $setting ??= (new Setting)->forceFill(['key' => $key->value]);
                $setting->forceFill(['value' => $after, 'updated_by_id' => $actor->id])->save();
                // Keys are longer than a ULID subject id: the key is the subject label.
                $this->audit->record('settings.updated', null, [$key->value => $before], [$key->value => $after], trim($reason), $actor, $key->value);
                $changed[] = $key->value;
            }

            return $changed;
        }, attempts: 3);   // two first-ever edits of a key (gap locks) deadlock; the loser retries

        // After commit (also when called inside an outer transaction), so no reader re-caches the old value.
        DB::afterCommit(fn () => $this->settings->flush());

        return $changed;
    }
}
