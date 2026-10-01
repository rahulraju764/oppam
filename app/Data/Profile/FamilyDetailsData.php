<?php

declare(strict_types=1);

namespace App\Data\Profile;

/** Wizard step 3 — family (M02). Keys match family_details. */
final readonly class FamilyDetailsData
{
    public function __construct(
        public ?string $father_name,
        public ?string $father_occupation,
        public ?string $mother_name,
        public ?string $mother_occupation,
        public ?int $brothers_married,
        public ?int $brothers_unmarried,
        public ?int $sisters_married,
        public ?int $sisters_unmarried,
        public ?int $family_status_option_id,
        public ?int $family_type_option_id,
        public ?int $family_values_option_id,
        public ?string $native_place,
        public ?string $about_family,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
