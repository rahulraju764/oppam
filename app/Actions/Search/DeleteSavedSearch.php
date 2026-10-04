<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Models\Profile;
use App\Models\SavedSearch;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class DeleteSavedSearch
{
    public function handle(Profile $profile, SavedSearch $savedSearch): void
    {
        if ($savedSearch->profile_id !== $profile->id) {
            throw (new ModelNotFoundException)->setModel(SavedSearch::class, [$savedSearch->id]);
        }

        $savedSearch->delete();
    }
}
