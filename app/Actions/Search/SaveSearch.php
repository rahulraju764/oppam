<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Data\Search\SearchCriteria;
use App\Domain\Profile\ProfileVisibility;
use App\Domain\Search\SavedSearchName;
use App\Enums\AlertFrequency;
use App\Exceptions\Search\SavedSearchLimitReached;
use App\Exceptions\Search\SearchThrottled;
use App\Models\Profile;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save the current search with an alert frequency (M04). Only a member who may browse can save;
 * the filters stored are the normalised criteria (SearchCriteria::toInput()), never raw input.
 * At most SavedSearch::MAX_PER_PROFILE per profile — counted under a lock on the owner's profile
 * row, so two quick saves can't make it eleven. MAX_PER_MINUTE saves per member.
 */
final class SaveSearch
{
    public const MAX_PER_MINUTE = 10;

    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly RateLimiter $limiter,
    ) {}

    /** @throws ValidationException|SavedSearchLimitReached|SearchThrottled */
    public function handle(User $member, string $name, SearchCriteria $criteria, AlertFrequency $frequency = AlertFrequency::Daily): SavedSearch
    {
        $profile = $member->profile;
        abort_unless($profile !== null && $this->visibility->canBrowse($member), 403);

        $name = SavedSearchName::validate($name);

        $key = 'saved-search:'.$member->id;
        if ($this->limiter->tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            throw new SearchThrottled($this->limiter->availableIn($key));
        }
        $this->limiter->hit($key, 60);

        return DB::transaction(function () use ($profile, $name, $criteria, $frequency): SavedSearch {
            Profile::query()->whereKey($profile->id)->lockForUpdate()->value('id');

            if (SavedSearch::query()->where('profile_id', $profile->id)->count() >= SavedSearch::MAX_PER_PROFILE) {
                throw SavedSearchLimitReached::make(SavedSearch::MAX_PER_PROFILE);
            }

            $search = new SavedSearch([
                'name' => $name,
                'filters' => $criteria->toInput(),
                'alert_frequency' => $frequency,
            ]);
            $search->profile_id = $profile->id;
            $search->save();

            return $search;
        });
    }
}
