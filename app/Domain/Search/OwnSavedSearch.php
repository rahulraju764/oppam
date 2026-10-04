<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Profile\ProfileVisibility;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** A saved search, looked up among the member's own only (CLAUDE.md rule 3: foreign → 404). */
final class OwnSavedSearch
{
    /** @throws ModelNotFoundException<SavedSearch> */
    public static function find(User $member, string $savedSearchId): SavedSearch
    {
        // A member who may no longer browse (suspended meanwhile) can't change saved searches either.
        abort_unless(app(ProfileVisibility::class)->canBrowse($member), 403);

        $profileId = $member->profile?->id;

        return SavedSearch::query()
            ->where('profile_id', $profileId ?? '')
            ->whereKey($savedSearchId)
            ->firstOrFail();
    }
}
