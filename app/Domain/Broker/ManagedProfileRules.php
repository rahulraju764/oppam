<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Domain\Profile\ProfileRules;
use App\ValueObjects\PhoneNumber;
use Closure;

/**
 * The ONE rule set for a new managed client (§11A B.17, R-M13-28 "imported rows use the identical
 * validation rules as the single form"): the M02 step-1 rules (ProfileRules::basic, in full), the
 * client mobile (required, a valid number — the future claim key) and the client's consent. The
 * single "Add profile" form (CreateManagedProfile) and every bulk-import row (ImportRowValidator)
 * validate through here; ImportValidationParityTest holds them to it.
 *
 * An import row also carries the PRD's required location and a few optional wizard fields
 * (forImport): those use the wizard's own rules (ProfileRules::career / about), never copies.
 */
final class ManagedProfileRules
{
    /**
     * The single form: basics + client mobile + consent (a checkbox → `accepted`).
     *
     * @param  array<string, mixed>  $basic  the step-1 values (for dependent rules: age by gender, caste by religion)
     * @return array<string, list<mixed>>
     */
    public static function forCreate(array $basic): array
    {
        return [
            'clientPhone' => self::clientPhone(),
            'consent' => ['accepted'],
            ...ProfileRules::basic($basic),
        ];
    }

    /**
     * One import row, keyed by the template's field names: everything forCreate() checks (the
     * consent cell must say YES — the parser turns YES into true, anything else into false), plus
     * the required current location (country, and state + district where the country has them —
     * ProfileRules::career's own rule) and the optional education / career / about fields.
     *
     * @param  array<string, mixed>  $values  the parsed row
     * @return array<string, list<mixed>>
     */
    public static function forImport(array $values): array
    {
        $career = ProfileRules::career($values);   // full: the location's "state + district when the country has states"

        return [
            ...self::forCreate($values),
            'current_country_id' => $career['current_country_id'],
            'current_state_id' => $career['current_state_id'],
            'current_district_id' => $career['current_district_id'],
            ...array_intersect_key(ProfileRules::career($values, partial: true), array_flip(self::OPTIONAL_CAREER)),
            'about' => ProfileRules::about(partial: true)['about'],
        ];
    }

    /** Optional wizard step-2 fields an import row may carry (decisions 2026-10-26: core sections). */
    public const OPTIONAL_CAREER = ['education_id', 'education_detail', 'employer_type', 'occupation_id', 'income_band_id', 'current_city'];

    /** @return list<mixed> */
    public static function clientPhone(): array
    {
        return ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || PhoneNumber::tryParse($value) === null) {
                $fail(__('Enter a valid mobile number (an Indian number, or start with + for abroad).'));
            }
        }];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['consent.accepted' => __('Please confirm the client has agreed before you save the profile.')];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return ['clientPhone' => __('client mobile'), ...ProfileRules::attributes()];
    }
}
