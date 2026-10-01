<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Masters\Caste;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Multi-select version of CasteBelongsToReligion for partner preferences (M02 step 4): every
 * chosen caste must be an ACTIVE caste of one of the chosen religions. Castes without a
 * religion chosen are refused — "any religion" can't narrow to castes.
 */
final class CastesBelongToReligions implements ValidationRule
{
    /** @param  list<int>  $religionIds */
    public function __construct(private readonly array $religionIds) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $ids = array_values(array_unique(array_map(intval(...), array_filter($value, is_numeric(...)))));

        $belongs = $this->religionIds !== []
            && count($ids) === count($value)
            && Caste::query()->active()->whereIn('religion_id', $this->religionIds)->whereKey($ids)->count() === count($ids);

        if (! $belongs) {
            $fail(__('Choose castes from the list for the selected religions.'));
        }
    }
}
