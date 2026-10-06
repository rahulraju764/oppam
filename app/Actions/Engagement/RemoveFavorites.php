<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Enums\Entitlement;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Favorite;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Facades\DB;

/**
 * Remove several of the member's own favourites at once (PRD M06 `/favorites` multi-select).
 * Profiles are named by code and matched only inside the member's OWN favourites — a code that
 * isn't one of theirs changes nothing. The plan's slots are given back with the rows, in one
 * transaction. At most MAX codes per call.
 */
final class RemoveFavorites
{
    public const MAX = 50;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  list<mixed>  $profileCodes
     * @return int how many favourites were removed
     *
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, array $profileCodes): int
    {
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        $codes = array_slice(array_values(array_unique(array_filter(
            $profileCodes,
            fn (mixed $code): bool => is_string($code) && preg_match('/^OPM\d{1,10}$/', $code) === 1,
        ))), 0, self::MAX);

        if ($own === null || $codes === []) {
            return 0;
        }

        return DB::transaction(function () use ($own, $codes): int {
            $removed = Favorite::query()
                ->where('profile_id', $own->id)
                ->whereIn('favorite_profile_id', Profile::withTrashed()->select('id')->whereIn('code', $codes))
                ->delete();

            if ($removed > 0) {
                $this->entitlements->release($own, Entitlement::FavoritesTotal, $removed);
            }

            return $removed;
        });
    }
}
