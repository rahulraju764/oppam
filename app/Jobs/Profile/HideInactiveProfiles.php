<?php

declare(strict_types=1);

namespace App\Jobs\Profile;

use App\Domain\Profile\MemberHiding;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Notifications\Account\ProfileHiddenForInactivity;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A15 "profile auto-hide after inactivity (180 d)" (P8.4), daily 03:20 IST: a member's own ACTIVE
 * profile not active for `profile.auto_hide_inactive_days` is HIDDEN the same way the member would
 * hide it (MemberHiding — reversible with one switch, the earlier status kept), audited as a
 * system action, and the member is told why (account_status, mandatory). Never a bureau-run
 * profile (its family hasn't signed up; the bureau manages its visibility). A profile never
 * active since publishing counts from its publish date. One profile's failure doesn't stop the rest.
 */
final class HideInactiveProfiles implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const CHUNK = 500;

    /** Below the queue's retry_after (960 s). */
    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function handle(MemberHiding $hiding, AuditLogger $audit): void
    {
        $days = Settings::int(SettingKey::ProfileAutoHideInactiveDays);
        $cutoff = now()->subDays($days);

        Profile::query()
            ->where('status', ProfileStatus::Active->value)
            ->whereNull('managed_by_broker_id')
            ->whereNotNull('user_id')
            ->whereHas('user', fn ($q) => $q->where('role', UserRole::Member->value))
            ->where(fn ($q) => $q->where('last_active_at', '<', $cutoff)
                ->orWhere(fn ($never) => $never->whereNull('last_active_at')->where('published_at', '<', $cutoff)))
            ->select('id')
            ->chunkById(self::CHUNK, function ($chunk) use ($hiding, $audit, $days, $cutoff): void {
                foreach ($chunk as $row) {
                    try {
                        $this->hideOne((string) $row->id, $hiding, $audit, $days, $cutoff);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }

    private function hideOne(string $profileId, MemberHiding $hiding, AuditLogger $audit, int $days, DateTimeInterface $cutoff): void
    {
        $profile = DB::transaction(function () use ($profileId, $hiding, $audit, $days, $cutoff): ?Profile {
            $profile = Profile::query()->with('user')->whereKey($profileId)->lockForUpdate()->first();
            // Re-checked under the lock: the member may have come back since the query.
            if ($profile === null) {
                return null;
            }
            $lastSeen = $profile->last_active_at ?? $profile->published_at;
            if ($lastSeen === null || $lastSeen->greaterThanOrEqualTo($cutoff) || ! $hiding->hide($profile)) {
                return null;
            }

            $audit->record('profile.auto_hidden_inactive', $profile, ['status' => 'ACTIVE'], ['status' => $profile->status->value],
                reason: "No activity for {$days} days", subjectLabel: $profile->code);

            return $profile;
        });

        $profile?->user?->notify(new ProfileHiddenForInactivity($days));
    }
}
