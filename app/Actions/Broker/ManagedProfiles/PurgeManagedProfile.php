<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Actions\Profile\EraseProfileData;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Models\BrokerClientNote;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The end of a managed profile's 30-day recovery (§11A B.6, decisions 2026-10-25): once deleted
 * longer than `oppam.broker.managed_restore_days`, everything that identifies the client goes —
 * the same erasure as a purged member (EraseProfileData: files first, then rows), plus the
 * bureau's client notes and the client mobile. The row stays as a tombstone (DELETED, or DRAFT) so ledger,
 * moderation and audit rows keep pointing at something; the consent stamp stays as evidence.
 * Legal holds (A10) are skipped. Idempotent: a purged (no client mobile) or not-yet-due profile is
 * left alone. Run by PurgeDeletedManagedProfiles (daily).
 */
final class PurgeManagedProfile
{
    public function __construct(
        private readonly EraseProfileData $eraser,
        private readonly AuditLogger $audit,
    ) {}

    /** @return bool whether the profile was purged now */
    public function handle(Profile $profile): bool
    {
        if (! self::isDue($profile)) {
            return false;
        }

        $this->eraser->files($profile);

        return DB::transaction(function () use ($profile): bool {
            $profile = Profile::withTrashed()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            if (! self::isDue($profile)) {
                return false;
            }

            $this->eraser->rows($profile);
            BrokerClientNote::query()->where('profile_id', $profile->id)->delete();

            // A DRAFT stays DRAFT: its basics may be missing (DB CHECK chk_profiles_basics_unless_draft).
            $profile->forceFill([
                'client_phone' => null,
                'status' => $profile->status === ProfileStatus::Draft ? ProfileStatus::Draft : ProfileStatus::Deleted,
                'previous_status' => null,
            ])->save();

            $this->audit->record('broker.profile_purged', $profile, after: ['purged' => true], subjectLabel: $profile->code);

            return true;
        });
    }

    private static function isDue(Profile $profile): bool
    {
        return $profile->owner_type === ProfileOwnerType::BrokerManaged
            && $profile->user_id === null
            && $profile->trashed()
            && $profile->client_phone !== null
            && $profile->legal_hold_at === null
            && $profile->deleted_at?->lte(now()->subDays((int) config('oppam.broker.managed_restore_days'))) === true;
    }
}
