<?php

declare(strict_types=1);

namespace App\Data\Profile;

/**
 * Wizard step 4 — partner preferences (M02). Keys match partner_preferences. Multi-selects are
 * lists (an empty list means "any"); values are raw until the Action validates them.
 */
final readonly class PartnerPreferenceData
{
    /**
     * @param  list<string>  $marital_statuses
     * @param  list<string>  $physical_statuses
     * @param  list<int>  $religion_ids
     * @param  list<int>  $caste_ids
     * @param  list<int>  $mother_tongue_ids
     * @param  list<int>  $star_ids
     * @param  list<int>  $education_ids
     * @param  list<int>  $occupation_ids
     * @param  list<int>  $country_ids
     * @param  list<int>  $district_ids
     * @param  list<int>  $diet_option_ids
     */
    public function __construct(
        public ?int $age_min,
        public ?int $age_max,
        public ?int $height_min_cm,
        public ?int $height_max_cm,
        public array $marital_statuses,
        public array $physical_statuses,
        public array $religion_ids,
        public array $caste_ids,
        public array $mother_tongue_ids,
        public array $star_ids,
        public array $education_ids,
        public array $occupation_ids,
        public ?int $min_income_band_id,
        public array $country_ids,
        public array $district_ids,
        public array $diet_option_ids,
        public ?string $about_partner,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
