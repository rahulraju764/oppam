<?php

declare(strict_types=1);

namespace App\Data\Profile;

/**
 * Wizard step 5 — contact (M02). Keys match contact_details. The primary mobile is the verified
 * users.phone and is never part of this data.
 */
final readonly class ContactDetailsData
{
    public function __construct(
        public ?string $contact_email,
        public ?string $alternate_phone,
        public ?string $contact_person,
        public ?string $contact_relation,
        public ?string $convenient_time,
        public ?int $country_id,
        public ?int $state_id,
        public ?int $district_id,
        public ?string $city,
        public ?string $address_line,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
