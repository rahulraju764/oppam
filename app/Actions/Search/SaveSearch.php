<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Enums\AlertFrequency;
use App\Exceptions\Search\SavedSearchLimitReached;
use App\Models\Profile;
use App\Models\SavedSearch;
use Illuminate\Validation\ValidationException;

/**
 * Save a search with alert frequency (PRD §10 M04).
 * Enforces the 10 saved searches quota per member profile.
 */
final class SaveSearch
{
    /**
     * @param  array<string, mixed>  $filters
     *
     * @throws ValidationException|SavedSearchLimitReached
     */
    public function handle(
        Profile $profile,
        string $name,
        array $filters,
        AlertFrequency $frequency = AlertFrequency::Daily,
    ): SavedSearch {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages([
                'name' => __('The search name must be between 1 and 60 characters.'),
            ]);
        }

        $existingCount = SavedSearch::query()->where('profile_id', $profile->id)->count();

        if ($existingCount >= SavedSearch::MAX_PER_PROFILE) {
            throw SavedSearchLimitReached::make(SavedSearch::MAX_PER_PROFILE);
        }

        return SavedSearch::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'filters' => $filters,
            'alert_frequency' => $frequency,
            'last_alerted_at' => null,
        ]);
    }
}
