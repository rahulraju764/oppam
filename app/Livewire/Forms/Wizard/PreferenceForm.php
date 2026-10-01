<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Wizard;

use App\Data\Profile\PartnerPreferenceData;
use App\Domain\Profile\ProfileRules;
use App\Enums\Gender;
use App\Models\Profile;
use App\Services\Masters\Masters;

/**
 * Wizard step 4 — partner preferences (template partner.php). Rules: ProfileRules::preferences.
 * Multi-selects are string lists (empty = "any"). A new member starts with a sensible range
 * around their own age and their own religion.
 */
final class PreferenceForm extends WizardForm
{
    /** The multi-select fields, also the allow-list for toggle(). */
    public const LISTS = ['marital_statuses', 'physical_statuses', 'religion_ids', 'caste_ids', 'mother_tongue_ids',
        'star_ids', 'education_ids', 'occupation_ids', 'country_ids', 'district_ids', 'diet_option_ids'];

    private const SCALARS = ['age_min', 'age_max', 'height_min_cm', 'height_max_cm', 'min_income_band_id', 'about_partner'];

    public string $age_min = '';

    public string $age_max = '';

    public string $height_min_cm = '';

    public string $height_max_cm = '';

    public string $min_income_band_id = '';

    public string $about_partner = '';

    /** @var list<string> */
    public array $marital_statuses = [];

    /** @var list<string> */
    public array $physical_statuses = [];

    /** @var list<string> */
    public array $religion_ids = [];

    /** @var list<string> */
    public array $caste_ids = [];

    /** @var list<string> */
    public array $mother_tongue_ids = [];

    /** @var list<string> */
    public array $star_ids = [];

    /** @var list<string> */
    public array $education_ids = [];

    /** @var list<string> */
    public array $occupation_ids = [];

    /** @var list<string> */
    public array $country_ids = [];

    /** @var list<string> */
    public array $district_ids = [];

    /** @var list<string> */
    public array $diet_option_ids = [];

    public function load(Profile $profile): void
    {
        $preference = $profile->partnerPreference;

        if ($preference === null) {
            $this->suggest($profile);

            return;
        }

        foreach (self::SCALARS as $field) {
            $this->{$field} = self::toInput($preference->getAttribute($field));
        }

        foreach (self::LISTS as $list) {
            $values = $preference->getAttribute($list);
            $this->{$list} = array_values(array_map(strval(...), is_array($values) ? $values : []));
        }
    }

    /** Add or remove one value of a multi-select (option buttons). Unknown lists are ignored. */
    public function toggle(string $list, string $value): void
    {
        if (! in_array($list, self::LISTS, true)) {
            return;
        }

        $current = $this->{$list};
        $this->{$list} = in_array($value, $current, true)
            ? array_values(array_diff($current, [$value]))
            : [...$current, $value];
    }

    /** "Any": clear a multi-select. */
    public function clear(string $list): void
    {
        if (in_array($list, self::LISTS, true)) {
            $this->{$list} = [];
        }
    }

    /** Castes must belong to a chosen religion: drop the ones that no longer do. */
    public function keepCastesOfReligions(Masters $masters): void
    {
        $allowed = [];
        foreach ($this->religion_ids as $religionId) {
            foreach ($masters->castesForReligion((int) $religionId) as $caste) {
                $allowed[] = (string) $caste->id;
            }
        }

        $this->caste_ids = array_values(array_intersect($this->caste_ids, $allowed));
    }

    public function toData(): PartnerPreferenceData
    {
        $ints = fn (array $values): array => array_values(array_map(intval(...), array_filter($values, is_numeric(...))));

        return new PartnerPreferenceData(
            age_min: self::int($this->age_min),
            age_max: self::int($this->age_max),
            height_min_cm: self::int($this->height_min_cm),
            height_max_cm: self::int($this->height_max_cm),
            marital_statuses: array_map(strval(...), $this->marital_statuses),
            physical_statuses: array_map(strval(...), $this->physical_statuses),
            religion_ids: $ints($this->religion_ids),
            caste_ids: $ints($this->caste_ids),
            mother_tongue_ids: $ints($this->mother_tongue_ids),
            star_ids: $ints($this->star_ids),
            education_ids: $ints($this->education_ids),
            occupation_ids: $ints($this->occupation_ids),
            min_income_band_id: self::int($this->min_income_band_id),
            country_ids: $ints($this->country_ids),
            district_ids: $ints($this->district_ids),
            diet_option_ids: $ints($this->diet_option_ids),
            about_partner: self::str($this->about_partner),
        );
    }

    /**
     * Rules need the member's own gender (the partner's minimum age), which the form doesn't
     * hold; the wizard passes it in.
     *
     * @return array<string, list<mixed>>
     */
    public function rulesFor(Gender $ownGender): array
    {
        return ProfileRules::preferences($this->toData()->toArray(), $ownGender);
    }

    /** First visit: brides look 0–7 years older, grooms 0–7 years younger; same religion. */
    private function suggest(Profile $profile): void
    {
        $age = $profile->age();

        if ($age !== null) {
            $floor = ProfileRules::partnerMinimumAge($profile->gender);
            [$min, $max] = $profile->gender === Gender::Female ? [$age, $age + 7] : [$age - 7, $age];
            $this->age_min = (string) min(ProfileRules::PARTNER_AGE_MAX, max($floor, $min));
            $this->age_max = (string) min(ProfileRules::PARTNER_AGE_MAX, max($floor, $max));
        }

        if ($profile->religion_id !== null) {
            $this->religion_ids = [(string) $profile->religion_id];
        }
    }
}
