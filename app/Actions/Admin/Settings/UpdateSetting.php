<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

use App\Enums\SettingKey;
use App\Models\AdminUser;

/**
 * Change one admin-editable setting (PRD A15: "editable, validated, audited"). One key of
 * UpdateSettings — the single write path (validation against the key's own rules, settings.edit,
 * a required reason, before / after audit, cache flushed after commit).
 */
final class UpdateSetting
{
    public function __construct(private readonly UpdateSettings $settings) {}

    public function handle(AdminUser $actor, SettingKey $key, mixed $value, string $reason): void
    {
        $this->settings->handle($actor, [$key->value => $value], $reason);
    }
}
