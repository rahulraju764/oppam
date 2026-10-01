<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Rasi;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use App\Models\Masters\State;
use App\Rules\ActiveMaster;
use App\Rules\CasteBelongsToReligion;
use App\Rules\MinimumMarriageAge;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;
use Illuminate\Validation\Rule;

/**
 * THE validation rules for profile data, one method per wizard step (M02). Used by the wizard's
 * Form Objects (live errors), re-checked by the Save…Details Actions (server truth), and — from
 * Phase 7 — by the broker managed-profile form and bulk import (§11A: "identical validation").
 * Keys are the column names. `$partial` (autosave) drops "required" so a half-filled step can be
 * kept, but every value that IS given must still be valid.
 *
 * @phpstan-type Rules array<string, list<mixed>>
 */
final class ProfileRules
{
    /** Letters (any script, incl. Malayalam), spaces, dots, apostrophes and hyphens. */
    public const NAME_REGEX = "regex:/^[\p{L}\p{M}][\p{L}\p{M} .'-]*$/u";

    /**
     * Step 1 — basic details (profiles + horoscope_details).
     *
     * @param  array<string, mixed>  $input  the values being validated (for dependent rules)
     * @return array<string, list<mixed>>
     */
    public static function basic(array $input, bool $partial = false): array
    {
        $req = self::required($partial);
        $gender = Gender::tryFrom((string) ($input['gender'] ?? ''));

        return [
            // First name and gender are NOT NULL on profiles: required even for an autosave.
            'first_name' => ['required', 'string', 'min:2', 'max:60', self::NAME_REGEX],
            'last_name' => [...$req, 'nullable', 'string', 'min:1', 'max:60', self::NAME_REGEX],
            'gender' => ['required', Rule::enum(Gender::class)],
            'dob' => [...$req, 'nullable', 'date_format:Y-m-d', new MinimumMarriageAge($gender)],
            'height_cm' => [...$req, 'nullable', 'integer', 'between:'.HeightCm::MIN.','.HeightCm::MAX],
            'weight_kg' => ['nullable', 'integer', 'between:30,200'],
            'marital_status' => [...$req, 'nullable', Rule::enum(MaritalStatus::class)],
            // Ignored (stored as 0) for NEVER_MARRIED by the Action.
            'children_count' => ['nullable', 'integer', 'between:0,10'],
            'physical_status' => ['required', Rule::enum(PhysicalStatus::class)],
            'religion_id' => [...$req, 'nullable', new ActiveMaster(Religion::class)],
            'caste_id' => ['nullable', new CasteBelongsToReligion(self::intOrNull($input['religion_id'] ?? null))],
            'caste_no_bar' => ['boolean'],
            'sub_caste' => ['nullable', 'string', 'max:80'],
            'mother_tongue_id' => [...$req, 'nullable', new ActiveMaster(MotherTongue::class)],
            'star_id' => ['nullable', new ActiveMaster(Star::class)],
            'rasi_id' => ['nullable', new ActiveMaster(Rasi::class)],
            'chovva_dosham' => ['nullable', Rule::enum(Dosham::class)],
            'papa_dosham' => ['nullable', Rule::enum(Dosham::class)],
        ];
    }

    /**
     * Step 2 — education & career, current and permanent location (education_careers).
     * State and district are required when the chosen country has states in master data (India).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public static function career(array $input, bool $partial = false): array
    {
        $req = self::required($partial);
        $currentCountry = self::intOrNull($input['current_country_id'] ?? null);
        $permanentCountry = self::intOrNull($input['permanent_country_id'] ?? null);
        $currentHasStates = ! $partial && self::countryHasStates($currentCountry);
        $permanentHasStates = ! $partial && self::countryHasStates($permanentCountry);

        return [
            'education_id' => [...$req, 'nullable', new ActiveMaster(Education::class)],
            'education_detail' => ['nullable', 'string', 'max:150'],
            'employer_type' => [...$req, 'nullable', Rule::enum(EmployerType::class)],
            'occupation_id' => [...$req, 'nullable', new ActiveMaster(Occupation::class)],
            'employer_name' => ['nullable', 'string', 'max:150'],
            'income_band_id' => [...$req, 'nullable', new ActiveMaster(IncomeBand::class)],
            'current_country_id' => [...$req, 'nullable', new ActiveMaster(Country::class)],
            'current_state_id' => [$currentHasStates ? 'required' : 'nullable', 'nullable', new ActiveMaster(State::class, ['country_id' => $currentCountry])],
            'current_district_id' => [$currentHasStates ? 'required' : 'nullable', 'nullable', new ActiveMaster(District::class, ['state_id' => self::intOrNull($input['current_state_id'] ?? null)])],
            'current_city' => ['nullable', 'string', 'max:80'],
            'citizenship' => ['nullable', 'string', 'max:80'],
            'visa_status' => ['nullable', 'string', 'max:80'],
            'permanent_country_id' => [...$req, 'nullable', new ActiveMaster(Country::class)],
            'permanent_state_id' => [$permanentHasStates ? 'required' : 'nullable', 'nullable', new ActiveMaster(State::class, ['country_id' => $permanentCountry])],
            'permanent_district_id' => [$permanentHasStates ? 'required' : 'nullable', 'nullable', new ActiveMaster(District::class, ['state_id' => self::intOrNull($input['permanent_state_id'] ?? null)])],
            'permanent_city' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * Step 3 — family (family_details).
     *
     * @return array<string, list<mixed>>
     */
    public static function family(bool $partial = false): array
    {
        $req = self::required($partial);

        return [
            'father_name' => [...$req, 'nullable', 'string', 'max:100', self::NAME_REGEX],
            'father_occupation' => ['nullable', 'string', 'max:100'],
            'mother_name' => [...$req, 'nullable', 'string', 'max:100', self::NAME_REGEX],
            'mother_occupation' => ['nullable', 'string', 'max:100'],
            'brothers_married' => ['nullable', 'integer', 'between:0,15'],
            'brothers_unmarried' => ['nullable', 'integer', 'between:0,15'],
            'sisters_married' => ['nullable', 'integer', 'between:0,15'],
            'sisters_unmarried' => ['nullable', 'integer', 'between:0,15'],
            'family_status_option_id' => [...$req, 'nullable', new ActiveMaster(MasterOption::class, ['group' => 'family_status'])],
            'family_type_option_id' => ['nullable', new ActiveMaster(MasterOption::class, ['group' => 'family_type'])],
            'family_values_option_id' => ['nullable', new ActiveMaster(MasterOption::class, ['group' => 'family_values'])],
            'native_place' => ['nullable', 'string', 'max:100'],
            'about_family' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Friendly field names for error messages ("The date of birth field is required", not "dob").
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'first_name' => __('first name'),
            'last_name' => __('last name'),
            'gender' => __('gender'),
            'dob' => __('date of birth'),
            'height_cm' => __('height'),
            'weight_kg' => __('weight'),
            'marital_status' => __('marital status'),
            'children_count' => __('number of children'),
            'physical_status' => __('physical status'),
            'religion_id' => __('religion'),
            'caste_id' => __('caste'),
            'sub_caste' => __('sub-caste'),
            'mother_tongue_id' => __('mother tongue'),
            'star_id' => __('star'),
            'rasi_id' => __('rasi'),
            'chovva_dosham' => __('chovva dosham'),
            'papa_dosham' => __('papa dosham'),
            'education_id' => __('highest education'),
            'education_detail' => __('education detail'),
            'employer_type' => __('employed in'),
            'occupation_id' => __('occupation'),
            'employer_name' => __('company'),
            'income_band_id' => __('annual income'),
            'current_country_id' => __('country'),
            'current_state_id' => __('state'),
            'current_district_id' => __('district'),
            'current_city' => __('city'),
            'permanent_country_id' => __('country'),
            'permanent_state_id' => __('state'),
            'permanent_district_id' => __('district'),
            'permanent_city' => __('city'),
            'father_name' => __("father's name"),
            'father_occupation' => __("father's occupation"),
            'mother_name' => __("mother's name"),
            'mother_occupation' => __("mother's occupation"),
            'brothers_married' => __('married brothers'),
            'brothers_unmarried' => __('unmarried brothers'),
            'sisters_married' => __('married sisters'),
            'sisters_unmarried' => __('unmarried sisters'),
            'family_status_option_id' => __('family status'),
            'family_type_option_id' => __('family type'),
            'family_values_option_id' => __('family values'),
            'native_place' => __('native place'),
            'about_family' => __('about family'),
        ];
    }

    /** @return list<string> */
    private static function required(bool $partial): array
    {
        return $partial ? [] : ['required'];
    }

    private static function countryHasStates(?int $countryId): bool
    {
        return $countryId !== null && app(Masters::class)->statesForCountry($countryId) !== [];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
