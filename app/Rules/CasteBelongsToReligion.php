<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Masters\Caste;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The chosen caste must be an ACTIVE caste of the chosen religion (CLAUDE.md "Caste is always
 * filtered by religion"). The dropdown already filters, but a tampered request must not be able
 * to pair, say, a Christian denomination with a Hindu profile. Shared by the wizard (M02), the
 * broker form (§11A) and bulk import.
 */
final class CasteBelongsToReligion implements ValidationRule
{
    public function __construct(private readonly ?int $religionId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $belongs = $this->religionId !== null
            && is_numeric($value)
            && Caste::query()->active()->forReligion($this->religionId)->whereKey((int) $value)->exists();

        if (! $belongs) {
            $fail(__('Choose a caste from the list for the selected religion.'));
        }
    }
}
