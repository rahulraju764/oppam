<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Enums\AlertFrequency;
use App\Models\Profile;
use App\Models\SavedSearch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class UpdateSavedSearch
{
    /**
     * @throws ValidationException
     */
    public function handle(
        Profile $profile,
        SavedSearch $savedSearch,
        ?string $name = null,
        ?AlertFrequency $frequency = null,
    ): SavedSearch {
        if ($savedSearch->profile_id !== $profile->id) {
            throw (new ModelNotFoundException)->setModel(SavedSearch::class, [$savedSearch->id]);
        }

        $updates = [];

        if ($name !== null) {
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 60) {
                throw ValidationException::withMessages([
                    'name' => __('The search name must be between 1 and 60 characters.'),
                ]);
            }
            $updates['name'] = $name;
        }

        if ($frequency !== null) {
            $updates['alert_frequency'] = $frequency;
        }

        if ($updates !== []) {
            $savedSearch->update($updates);
        }

        return $savedSearch;
    }
}
