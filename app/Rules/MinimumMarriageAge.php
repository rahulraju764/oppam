<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Profile\AgeCalculator;
use App\Enums\Gender;
use App\Enums\SettingKey;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Throwable;

/**
 * Date of birth must make the person at least the minimum marriage age for their gender —
 * 18 for women, 21 for men by default (M02 step 1, R-M02 acceptance), editable in A15 but never
 * below the legal minimum. "Today" is the IST date. Shared by the wizard, the broker form and
 * bulk import. Gender unknown → nothing to check yet (the gender field reports its own error).
 */
final class MinimumMarriageAge implements ValidationRule
{
    public function __construct(private readonly Gender|string|null $gender) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $gender = is_string($this->gender) ? Gender::tryFrom($this->gender) : $this->gender;

        if ($gender === null) {
            return;
        }

        try {
            // Strict Y-m-d only: parse() would accept relative strings such as "-30 years".
            $dob = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value, config('oppam.display_timezone')) : null;

            if (! $dob instanceof CarbonImmutable || $dob->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Not a Y-m-d date.');
            }

            $age = AgeCalculator::ageOn($dob, CarbonImmutable::now(config('oppam.display_timezone')));
        } catch (Throwable) {
            $fail(__('Enter a valid date of birth.'));

            return;
        }

        $minimum = app(SettingsRepository::class)->int($gender === Gender::Female ? SettingKey::MinAgeFemale : SettingKey::MinAgeMale);

        if ($age < $minimum) {
            $fail(__('The minimum age is :age.', ['age' => $minimum]));
        }
    }
}
