<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Enums\Entitlement;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Favorite;
use App\Models\User;
use App\Queries\Engagement\EngagementLists;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Facades\DB;

/**
 * Clear the member's favourites that can no longer be shown (PRD M06, §7.3): a bookmarked profile
 * that has since been hidden, suspended, deleted or blocked is not listed on /favorites, yet its
 * row still holds one of the plan's slots — this removes those rows and gives the slots back.
 * Only the member's OWN favourites; the visible ones are untouched.
 */
final class RemoveUnavailableFavorites
{
    public function __construct(
        private readonly EngagementLists $lists,
        private readonly EntitlementService $entitlements,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @return int how many favourites were removed
     *
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member): int
    {
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        if ($own === null) {
            return 0;
        }

        // Read first, then delete by id: MySQL refuses a DELETE whose subquery reads the same table.
        // All of them first, the visible ones second: a favourite added in another tab in between
        // is then simply not considered (never mistaken for an unavailable one).
        $all = Favorite::query()->where('profile_id', $own->id)->pluck('favorite_profile_id')->map(fn (mixed $id): string => (string) $id);
        $visible = $this->lists->favorites($own)->reorder()->pluck('profiles.id')->map(fn (mixed $id): string => (string) $id);
        $unavailable = $all->diff($visible)->values()->all();

        if ($unavailable === []) {
            return 0;
        }

        return DB::transaction(function () use ($own, $unavailable): int {
            $removed = Favorite::query()
                ->where('profile_id', $own->id)
                ->whereIn('favorite_profile_id', $unavailable)
                ->delete();

            if ($removed > 0) {
                $this->entitlements->release($own, Entitlement::FavoritesTotal, $removed);
            }

            return $removed;
        });
    }
}
