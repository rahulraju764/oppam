<?php

declare(strict_types=1);

namespace App\Rules;

use App\ValueObjects\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A mobile typed into one free-text field (wizard alternate mobile, M02 step 5): "+971 50…" is a
 * full international number, anything else is read as an Indian mobile (PhoneNumber::tryParse).
 * The Action stores the normalised E.164 form.
 */
final class MobileNumberInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || PhoneNumber::tryParse($value) === null) {
            $fail(__('Enter a valid mobile number.'));
        }
    }
}
