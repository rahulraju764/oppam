<?php

declare(strict_types=1);

namespace App\Data\Profile;

/** Wizard step 2 — education, career and location (M02). Keys match education_careers. */
final readonly class CareerDetailsData
{
    public function __construct(
        public ?int $education_id,
        public ?string $education_detail,
        public ?string $employer_type,
        public ?int $occupation_id,
        public ?string $employer_name,
        public ?int $income_band_id,
        public ?int $current_country_id,
        public ?int $current_state_id,
        public ?int $current_district_id,
        public ?string $current_city,
        public ?string $citizenship,
        public ?string $visa_status,
        public ?int $permanent_country_id,
        public ?int $permanent_state_id,
        public ?int $permanent_district_id,
        public ?string $permanent_city,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
