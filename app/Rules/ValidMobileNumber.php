<?php

declare(strict_types=1);

namespace App\Rules;

use App\ValueObjects\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * The number must be a valid mobile for the chosen country code (PhoneNumber rules). Used by
 * every form that takes a mobile: registration, login by code, forgot password.
 */
final class ValidMobileNumber implements ValidationRule
{
    public function __construct(private readonly string $countryCode) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            PhoneNumber::fromParts($this->countryCode, is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            $fail(__('Enter a valid mobile number.'));
        }
    }
}
