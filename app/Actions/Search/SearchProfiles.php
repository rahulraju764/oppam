<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Data\Search\SearchCriteria;
use App\Data\Search\SearchPage;
use App\Data\Search\SearchResult;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\SettingKey;
use App\Exceptions\Search\SearchThrottled;
use App\Models\User;
use App\Services\Profile\ProfileSearch;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;

/**
 * A member searches (M04): only a member who may browse at all (ProfileVisibility::canBrowse —
 * an active member whose own profile isn't suspended) gets results; anyone else gets an empty
 * page. Each request (a new search or "load more") counts against `search.max_per_minute`.
 * ProfileSearch applies every exclusion and filter.
 */
final class SearchProfiles
{
    public function __construct(
        private readonly ProfileSearch $search,
        private readonly ProfileVisibility $visibility,
        private readonly RateLimiter $limiter,
    ) {}

    /** @throws SearchThrottled */
    public function handle(User $searcher, SearchCriteria $criteria, ?string $cursor = null, bool $withCount = true): SearchResult
    {
        $profile = $searcher->profile;

        if ($profile === null || ! $this->visibility->canBrowse($searcher)) {
            return new SearchResult(new SearchPage(collect(), null), $withCount ? 0 : null);
        }

        $key = 'search:'.$searcher->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::SearchMaxPerMinute))) {
            throw new SearchThrottled($this->limiter->availableIn($key));
        }
        $this->limiter->hit($key, 60);

        $result = new SearchResult(
            $this->search->page($profile, $criteria, $cursor),
            $withCount ? $this->search->count($profile, $criteria) : null,
        );

        if ($cursor === null && $result->total !== null) {
            $this->log($criteria, $result->total);
        }

        return $result;
    }

    /**
     * A09 search usage (P9.1a): a new search (not "load more") is logged ANONYMOUSLY — the filter
     * keys it used, how many it found and the sort; never a filter's value, never who searched.
     */
    private function log(SearchCriteria $criteria, int $total): void
    {
        $input = $criteria->toInput();
        $sort = is_string($input['sort'] ?? null) ? $input['sort'] : null;
        unset($input['sort']);
        $keys = array_keys($input);   // toInput() already leaves out filters not in use
        sort($keys);

        DB::table('search_logs')->insert([
            'day' => now((string) config('oppam.display_timezone'))->toDateString(),
            'filters' => json_encode($keys),
            'result_count' => $total,
            'sort' => $sort !== null ? substr($sort, 0, 20) : null,
            'created_at' => now(),
        ]);
    }
}
