<?php

declare(strict_types=1);

namespace App\Actions\System;

use App\Data\System\AlertEvaluationData;
use App\Enums\AdminStatus;
use App\Enums\SystemAlertKind;
use App\Events\Admin\SystemAlertChanged;
use App\Models\AdminUser;
use App\Models\SystemAlert;
use App\Notifications\Admin\SystemAlertOpened;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A12 alerts (P9.2b2): bring system_alerts in line with one evaluation of the rules.
 *  - crossed, nothing open → OPEN it and email `system.alerts` holders once (after commit);
 *  - crossed, open → keep it open with the latest value (and forget any "clear since");
 *  - not crossed, open → note when it became clear; RESOLVE once clear for $clearMinutes
 *    (a value that flaps around its threshold doesn't send an email a minute);
 *  - unknown (source unreadable) → leave as is.
 * At most one open alert per kind (unique `open_key`): a parallel run that loses the insert race
 * is a no-op. Opening and resolving broadcast SystemAlertChanged to open dashboards.
 */
final class SyncSystemAlerts
{
    /** @return array{opened: list<SystemAlertKind>, resolved: list<SystemAlertKind>} */
    public function handle(AlertEvaluationData $evaluation, int $clearMinutes): array
    {
        $opened = [];
        $resolved = [];

        foreach (SystemAlertKind::cases() as $kind) {
            if ($evaluation->isUnknown($kind)) {
                continue;
            }

            $open = SystemAlert::query()->where('open_key', $kind->value)->first();
            $breach = $evaluation->breaches[$kind->value] ?? null;

            if ($breach !== null) {
                if ($open === null) {
                    if ($this->open($kind, $breach['value'], $breach['threshold'])) {
                        $opened[] = $kind;
                    }
                } else {
                    $open->forceFill(['value' => $breach['value'], 'threshold' => $breach['threshold'], 'last_seen_at' => now(), 'clear_since' => null])->save();
                }
            } elseif ($open !== null && $this->resolveIfClear($open, $clearMinutes)) {
                $resolved[] = $kind;
            }
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    private function open(SystemAlertKind $kind, int $value, int $threshold): bool
    {
        try {
            DB::transaction(function () use ($kind, $value, $threshold): void {
                (new SystemAlert)->forceFill([
                    'kind' => $kind,
                    'open_key' => $kind->value,
                    'value' => $value,
                    'threshold' => $threshold,
                    'opened_at' => now(),
                    'last_seen_at' => now(),
                    'notified_at' => now(),
                ])->save();

                $this->recipients()->each(fn (AdminUser $admin) => $admin->notify((new SystemAlertOpened($kind, $value, $threshold))->afterCommit()));
                SystemAlertChanged::dispatch($kind, SystemAlertChanged::OPENED);
            });
        } catch (UniqueConstraintViolationException) {
            return false;   // another check opened it a moment ago: that one sent the email
        }

        return true;
    }

    private function resolveIfClear(SystemAlert $alert, int $clearMinutes): bool
    {
        if ($alert->clear_since === null) {
            $alert->forceFill(['clear_since' => now()])->save();

            return false;
        }

        if ($alert->clear_since->gt(now()->subMinutes($clearMinutes))) {
            return false;
        }

        DB::transaction(function () use ($alert): void {
            $alert->forceFill(['resolved_at' => now(), 'open_key' => null])->save();
            SystemAlertChanged::dispatch($alert->kind, SystemAlertChanged::RESOLVED);
        });

        return true;
    }

    /** @return \Illuminate\Support\Collection<int, AdminUser> */
    private function recipients(): \Illuminate\Support\Collection
    {
        return AdminUser::query()->where('status', AdminStatus::Active->value)->get()
            ->filter(fn (AdminUser $admin): bool => $admin->can('system.alerts'))
            ->values();
    }
}
