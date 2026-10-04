<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Domain\Search\OwnSavedSearch;
use App\Domain\Search\SavedSearchName;
use App\Enums\AlertFrequency;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Rename a saved search or change its alert frequency (M04). The search is looked up among the
 * member's own only — anyone else's id is a 404 (ModelNotFoundException).
 */
final class UpdateSavedSearch
{
    /** @throws ValidationException */
    public function handle(User $member, string $savedSearchId, ?string $name = null, ?AlertFrequency $frequency = null): SavedSearch
    {
        $search = OwnSavedSearch::find($member, $savedSearchId);

        if ($name !== null) {
            $search->name = SavedSearchName::validate($name);
        }

        if ($frequency !== null) {
            $search->alert_frequency = $frequency;
        }

        $search->save();

        return $search;
    }
}
