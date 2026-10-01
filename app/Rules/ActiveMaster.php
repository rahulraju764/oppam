<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Masters\MasterRecord;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The id must be an ACTIVE row of a master list, optionally under a given parent
 * (state → country, district → state) or in a master_options group (diet, family_type…).
 * Dropdowns already filter, but a tampered request must not be able to store an inactive,
 * foreign or cross-parent value (CLAUDE.md "Master data"). Shared by the wizard (M02), the
 * broker form and bulk import (§11A B.17). A required parent that is missing fails too.
 */
final class ActiveMaster implements ValidationRule
{
    /**
     * @param  class-string<MasterRecord>  $model
     * @param  array<string, int|string|null>  $where  column => value constraints (parent id, group)
     */
    public function __construct(
        private readonly string $model,
        private readonly array $where = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($this->where as $constraint) {
            if ($constraint === null || $constraint === '') {
                $fail(__('Choose an option from the list.'));

                return;
            }
        }

        $exists = is_numeric($value)
            && $this->model::query()->active()->whereKey((int) $value)->where($this->where)->exists();

        if (! $exists) {
            $fail(__('Choose an option from the list.'));
        }
    }
}
