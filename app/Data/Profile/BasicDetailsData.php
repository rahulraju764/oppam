<?php

declare(strict_types=1);

namespace App\Data\Profile;

/**
 * Wizard step 1 — basic details (M02). Raw scalar values as entered (the Action validates them
 * with ProfileRules::basic before anything is written). Keys match the profile columns.
 */
final readonly class BasicDetailsData
{
    public function __construct(
        public ?string $first_name,
        public ?string $last_name,
        public ?string $gender,
        public ?string $dob,
        public ?int $height_cm,
        public ?int $weight_kg,
        public ?string $marital_status,
        public ?int $children_count,
        public ?string $physical_status,
        public ?int $religion_id,
        public ?int $caste_id,
        public bool $caste_no_bar,
        public ?string $sub_caste,
        public ?int $mother_tongue_id,
        public ?int $star_id,
        public ?int $rasi_id,
        public ?string $chovva_dosham,
        public ?string $papa_dosham,
    ) {}

    /** @return array<string, mixed> field => value, for validation */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
