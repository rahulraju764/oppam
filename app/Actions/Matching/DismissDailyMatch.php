<?php

declare(strict_types=1);

namespace App\Actions\Matching;

use App\Domain\Matching\DailyBatch;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;

/**
 * "Not interested" on a daily match (M05): hides that profile from the member's batch for today.
 * Found by profile code within the member's OWN batch for today only — any other code changes
 * nothing (no hint whether it exists).
 */
final class DismissDailyMatch
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /** @throws ImpersonationRestricted an admin looking around as the member changes nothing (P1.7b) */
    public function handle(User $member, string $code): void
    {
        $this->impersonation->assertAllowed();

        $me = $member->profile;
        if ($me === null || preg_match('/^OPM\d{1,10}$/', $code) !== 1) {
            return;
        }

        $matchedId = Profile::query()->where('code', $code)->value('id');
        if ($matchedId === null) {
            return;
        }

        DailyMatch::query()
            ->where('profile_id', $me->id)
            ->where('matched_profile_id', $matchedId)
            ->where('match_date', DailyBatch::today())
            ->update(['is_interacted' => true, 'updated_at' => now()]);
    }
}
