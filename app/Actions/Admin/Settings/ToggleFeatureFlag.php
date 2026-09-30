<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

use App\Enums\Flag;
use App\Models\AdminUser;
use App\Models\FeatureFlag;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Switch a feature flag on or off (PRD A15). Needs settings.edit; audited with a reason. */
final class ToggleFeatureFlag
{
    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $actor, Flag $flag, bool $enabled, string $reason): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']])->validate();

        DB::transaction(function () use ($actor, $flag, $enabled, $reason): void {
            $row = FeatureFlag::query()->lockForUpdate()->find($flag->value);
            $before = (bool) $row?->is_enabled;
            $row ??= (new FeatureFlag)->forceFill(['key' => $flag->value]);
            $row->forceFill(['is_enabled' => $enabled, 'updated_by_id' => $actor->id])->save();

            $this->audit->record('feature_flags.toggled', null, [$flag->value => $before], [$flag->value => $enabled], $reason, $actor, $flag->value);
        }, attempts: 3);   // two first-ever edits of a key (gap locks) deadlock; the loser retries

        DB::afterCommit(fn () => $this->flags->flush());
    }
}
