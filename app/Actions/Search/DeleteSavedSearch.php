<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Domain\Search\OwnSavedSearch;
use App\Models\User;

/** Delete one of the member's saved searches (M04); anyone else's id is a 404. */
final class DeleteSavedSearch
{
    public function handle(User $member, string $savedSearchId): void
    {
        OwnSavedSearch::find($member, $savedSearchId)->delete();
    }
}
