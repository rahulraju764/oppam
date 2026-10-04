<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\SavedSearch;
use Illuminate\Validation\ValidationException;

/** The one rule for a saved search's name (save and rename): trimmed, 1–60 characters. */
final class SavedSearchName
{
    /** @throws ValidationException */
    public static function validate(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '' || mb_strlen($name) > SavedSearch::NAME_MAX) {
            throw ValidationException::withMessages([
                'name' => __('The search name must be between 1 and :max characters.', ['max' => SavedSearch::NAME_MAX]),
            ]);
        }

        return $name;
    }
}
