<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhotoVisibility;
use App\Enums\PhysicalStatus;
use App\Enums\SettingKey;
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
use App\Models\Profile;
use App\Rules\ActiveMaster;
use App\Rules\CasteBelongsToReligion;
use App\Rules\CastesBelongToReligions;
use App\Rules\MinimumMarriageAge;
use App\Rules\MobileNumberInput;
use App\Services\Masters\Masters;
use App\Services\Settings\SettingsRepository;
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

    /** About me must say at least this much (M02 step 6). */
    public const ABOUT_MIN = 50;

    /** Hobbies (M02 step 6): how many, and how long each may be. */
    public const HOBBIES_MAX = 10;

    public const HOBBY_MAX_LENGTH = 40;

    /** Oldest partner age a preference can ask for (M02 step 4). */
    public const PARTNER_AGE_MAX = 70;

    /** Step-1 identity fields editable only until first publish; then via support (R-M02-1). */
    public const LOCKED_AFTER_PUBLISH = ['gender', 'dob', 'religion_id', 'marital_status'];

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
     * Step-1 fields the member may no longer change (R-M02-1): gender, DOB, religion and marital
     * status once the profile has been published, and gender whenever "profile for" implies it
     * (Son → male, Daughter → female …, M01).
     *
     * @return list<string>
     */
    public static function lockedFields(Profile $profile): array
    {
        $locked = $profile->published_at !== null ? self::LOCKED_AFTER_PUBLISH : [];

        if ($profile->user?->created_for->derivedGender() !== null) {
            $locked[] = 'gender';
        }

        return array_values(array_unique($locked));
    }

    /**
     * Step 4 — partner preferences (partner_preferences). Ages start at the legal minimum for the
     * partner's gender (A15 settings). Empty lists mean "any"; at least one religion is required.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public static function preferences(array $input, Gender $ownGender, bool $partial = false): array
    {
        $req = self::required($partial);
        $ages = 'between:'.self::partnerMinimumAge($ownGender).','.self::PARTNER_AGE_MAX;
        $heights = 'between:'.HeightCm::MIN.','.HeightCm::MAX;
        $religionIds = array_values(array_map(intval(...), array_filter(is_array($input['religion_ids'] ?? null) ? $input['religion_ids'] : [], is_numeric(...))));

        return [
            'age_min' => [...$req, 'nullable', 'integer', $ages],
            'age_max' => [...$req, 'nullable', 'integer', $ages, ...(is_numeric($input['age_min'] ?? null) ? ['gte:age_min'] : [])],
            'height_min_cm' => ['nullable', 'integer', $heights],
            'height_max_cm' => ['nullable', 'integer', $heights, ...(is_numeric($input['height_min_cm'] ?? null) ? ['gte:height_min_cm'] : [])],
            'marital_statuses' => ['array', 'max:10'],
            'marital_statuses.*' => ['distinct', Rule::enum(MaritalStatus::class)],
            'physical_statuses' => ['array', 'max:5'],
            'physical_statuses.*' => ['distinct', Rule::enum(PhysicalStatus::class)],
            'religion_ids' => [...$req, 'array', 'max:20'],
            'religion_ids.*' => ['distinct', new ActiveMaster(Religion::class)],
            'caste_ids' => ['array', 'max:100', new CastesBelongToReligions($religionIds)],
            'mother_tongue_ids' => ['array', 'max:30'],
            'mother_tongue_ids.*' => ['distinct', new ActiveMaster(MotherTongue::class)],
            'star_ids' => ['array', 'max:30'],
            'star_ids.*' => ['distinct', new ActiveMaster(Star::class)],
            'education_ids' => ['array', 'max:50'],
            'education_ids.*' => ['distinct', new ActiveMaster(Education::class)],
            'occupation_ids' => ['array', 'max:50'],
            'occupation_ids.*' => ['distinct', new ActiveMaster(Occupation::class)],
            'min_income_band_id' => ['nullable', new ActiveMaster(IncomeBand::class)],
            'country_ids' => ['array', 'max:50'],
            'country_ids.*' => ['distinct', new ActiveMaster(Country::class)],
            'district_ids' => ['array', 'max:50'],
            'district_ids.*' => ['distinct', new ActiveMaster(District::class)],
            'diet_option_ids' => ['array', 'max:10'],
            'diet_option_ids.*' => ['distinct', new ActiveMaster(MasterOption::class, ['group' => 'diet'])],
            'about_partner' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** The youngest partner a member may ask for: the legal minimum for the partner's gender (A15). */
    public static function partnerMinimumAge(Gender $ownGender): int
    {
        $partnerIsFemale = $ownGender !== Gender::Female;

        return app(SettingsRepository::class)->int($partnerIsFemale ? SettingKey::MinAgeFemale : SettingKey::MinAgeMale);
    }

    /**
     * Step 5 — contact (contact_details). The verified primary mobile is users.phone and is not
     * part of this step. State and district are required when the country has states (India).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public static function contact(array $input, bool $partial = false): array
    {
        $req = self::required($partial);
        $country = self::intOrNull($input['country_id'] ?? null);
        $hasStates = ! $partial && self::countryHasStates($country);

        return [
            'contact_email' => [...$req, 'nullable', 'string', 'email:rfc', 'max:255'],
            'alternate_phone' => ['nullable', 'string', 'max:20', new MobileNumberInput],
            'contact_person' => ['nullable', 'string', 'max:100', self::NAME_REGEX],
            'contact_relation' => ['nullable', 'string', 'max:40'],
            'convenient_time' => ['nullable', 'string', 'max:80'],
            'country_id' => [...$req, 'nullable', new ActiveMaster(Country::class)],
            'state_id' => [$hasStates ? 'required' : 'nullable', 'nullable', new ActiveMaster(State::class, ['country_id' => $country])],
            'district_id' => [$hasStates ? 'required' : 'nullable', 'nullable', new ActiveMaster(District::class, ['state_id' => self::intOrNull($input['state_id'] ?? null)])],
            'city' => [...$req, 'nullable', 'string', 'max:80'],
            'address_line' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Step 6 (non-photo part) — about me (min ABOUT_MIN characters), lifestyle, photo visibility.
     *
     * @return array<string, list<mixed>>
     */
    public static function about(bool $partial = false): array
    {
        $req = self::required($partial);

        return [
            'about' => [...$req, 'nullable', 'string', 'min:'.self::ABOUT_MIN, 'max:1000'],
            'diet_option_id' => ['nullable', new ActiveMaster(MasterOption::class, ['group' => 'diet'])],
            'smoking_option_id' => ['nullable', new ActiveMaster(MasterOption::class, ['group' => 'smoking'])],
            'drinking_option_id' => ['nullable', new ActiveMaster(MasterOption::class, ['group' => 'drinking'])],
            'hobbies' => ['array', 'max:'.self::HOBBIES_MAX],
            'hobbies.*' => ['string', 'max:'.self::HOBBY_MAX_LENGTH],
            'photo_visibility' => ['required', Rule::enum(PhotoVisibility::class)],
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
            'age_min' => __('minimum age'),
            'age_max' => __('maximum age'),
            'height_min_cm' => __('minimum height'),
            'height_max_cm' => __('maximum height'),
            'marital_statuses' => __('marital status'),
            'physical_statuses' => __('physical status'),
            'religion_ids' => __('religion'),
            'caste_ids' => __('caste'),
            'mother_tongue_ids' => __('mother tongue'),
            'star_ids' => __('star'),
            'education_ids' => __('education'),
            'occupation_ids' => __('occupation'),
            'min_income_band_id' => __('minimum income'),
            'country_ids' => __('country'),
            'district_ids' => __('district'),
            'diet_option_ids' => __('diet'),
            'about_partner' => __('about your partner'),
            'contact_email' => __('email address'),
            'alternate_phone' => __('alternate mobile number'),
            'contact_person' => __('contact person'),
            'contact_relation' => __('relationship'),
            'convenient_time' => __('convenient time to call'),
            'country_id' => __('country'),
            'state_id' => __('state'),
            'district_id' => __('district'),
            'city' => __('city'),
            'address_line' => __('address'),
            'about' => __('about me'),
            'diet_option_id' => __('diet'),
            'smoking_option_id' => __('smoking'),
            'drinking_option_id' => __('drinking'),
            'hobbies' => __('hobbies'),
            'photo_visibility' => __('photo visibility'),
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
