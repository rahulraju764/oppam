<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use App\Data\Search\SearchCriteria;
use App\Enums\MatchTab;
use App\Enums\SearchSort;
use App\Models\Profile;
use App\Services\Profile\ProfileSearch;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The member's match funnel (M05, My Matches + dashboard strips). Every tab is a ProfileSearch
 * (so blocked, ignored, incognito and non-ACTIVE profiles never appear) built from the member's
 * partner preferences (owner decision 2026-10-04):
 *   All = profiles that meet MY preferences · Mutual = those that also accept ME ·
 *   New = joined in 7 days · Yet to be viewed / Viewed = by me · Near me = my district ·
 *   Premium = premium members. Viewed lists every visible profile I opened, preferences or not.
 * The tab counts are cached per member for COUNTS_TTL seconds (seven counts at once are not
 * cheap); a view or a new member shows up in them within that time.
 */
final class MatchFunnel
{
    public const COUNTS_TTL = 600;

    public function __construct(
        private readonly ProfileSearch $search,
        private readonly Cache $cache,
    ) {}

    /** The tab's criteria; null when it can't have results (Near me without a district). */
    public function criteria(Profile $me, MatchTab $tab): ?SearchCriteria
    {
        $mine = SearchCriteria::fromPreferences($me->partnerPreference);

        return match ($tab) {
            MatchTab::All => $mine,
            MatchTab::Mutual => $mine->with(['mutualOnly' => true]),
            MatchTab::New => $mine->with(['newlyJoined' => true, 'sort' => SearchSort::Newest]),
            MatchTab::Unviewed => $mine->with(['hideViewed' => true]),
            MatchTab::Viewed => new SearchCriteria(viewedOnly: true, sort: SearchSort::LastActive),
            MatchTab::NearMe => $me->district_id === null ? null : $mine->with(['districtIds' => [(int) $me->district_id]]),
            MatchTab::Premium => $mine->with(['premiumOnly' => true]),
        };
    }

    /** @return array<string, int> tab value => count */
    public function counts(Profile $me): array
    {
        /** @var array<string, int> */
        return $this->cache->remember($this->countsKey($me), self::COUNTS_TTL, function () use ($me): array {
            $counts = [];
            foreach (MatchTab::cases() as $tab) {
                $criteria = $this->criteria($me, $tab);
                $counts[$tab->value] = $criteria === null ? 0 : $this->search->count($me, $criteria);
            }

            return $counts;
        });
    }

    public function forgetCounts(Profile $me): void
    {
        $this->cache->forget($this->countsKey($me));
    }

    private function countsKey(Profile $me): string
    {
        return 'matches:counts:'.$me->id;
    }
}
