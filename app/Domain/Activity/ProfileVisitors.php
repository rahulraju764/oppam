<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Data\Search\SearchCriteria;
use App\Models\Profile;
use App\Services\Profile\ProfileSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Who viewed my profile" (M15): distinct members who opened the member's profile in the last
 * `days` days, newest visit first (`last_viewed_at`). Read through ProfileSearch, so a visitor
 * in a blocked pair, ignored, incognito or no longer ACTIVE is never listed nor counted.
 */
final class ProfileVisitors
{
    public const WINDOW_DAYS = 90;

    public function __construct(private readonly ProfileSearch $search) {}

    /** @return Builder<Profile> */
    public function query(Profile $me, int $days = self::WINDOW_DAYS): Builder
    {
        $since = now()->subDays($days);

        return $this->search->query($me, new SearchCriteria)
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('profile_views')
                ->whereColumn('profile_views.viewer_profile_id', 'profiles.id')
                ->where('profile_views.viewed_profile_id', $me->id)
                ->where('profile_views.updated_at', '>=', $since))
            ->selectSub(fn (QueryBuilder $q) => $q->selectRaw('MAX(profile_views.updated_at)')->from('profile_views')
                ->whereColumn('profile_views.viewer_profile_id', 'profiles.id')
                ->where('profile_views.viewed_profile_id', $me->id), 'last_viewed_at')
            ->orderByDesc('last_viewed_at')
            ->orderByDesc('profiles.id');
    }

    public function count(Profile $me, int $days = self::WINDOW_DAYS): int
    {
        return $this->search->query($me, new SearchCriteria)
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('profile_views')
                ->whereColumn('profile_views.viewer_profile_id', 'profiles.id')
                ->where('profile_views.viewed_profile_id', $me->id)
                ->where('profile_views.updated_at', '>=', now()->subDays($days)))
            ->count();
    }
}
