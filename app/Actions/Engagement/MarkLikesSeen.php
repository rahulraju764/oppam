<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Like;
use App\Models\User;
use App\Services\Admin\Impersonation;

/**
 * The member has looked at "Liked me" (PRD M06 `likes.seen_at`): every like on their profile that
 * was still unseen is stamped, so its "new" dot shows this once. Does nothing during an admin
 * support session — an admin looking at the list must not use up the member's dots.
 */
final class MarkLikesSeen
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /** @return int how many likes were newly marked seen */
    public function handle(User $member): int
    {
        $own = $member->profile;

        try {
            $this->impersonation->assertAllowed();
        } catch (ImpersonationRestricted) {
            return 0;   // the page still opens for the admin; the member's dots are left alone
        }

        if ($own === null) {
            return 0;
        }

        return Like::query()->where('to_profile_id', $own->id)->whereNull('seen_at')->update(['seen_at' => now()]);
    }
}
