<?php

declare(strict_types=1);

namespace App\Data\Profile;

/**
 * Display data for one profile in a list (x-profile.row / .tile / .member-card).
 * Codes and display fields only — never the ULID, phone, email or a private photo URL.
 * `url` is null where the viewer may not open the profile (e.g. a logged-out visitor).
 */
final readonly class ProfileCardData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $photoUrl,
        public ?string $url = null,
        public ?string $age = null,
        public ?string $height = null,
        public ?string $education = null,
        public ?string $occupation = null,
        public ?string $place = null,
        /** "Last seen …" tail, or a context line such as "3 days ago" on the interests page. */
        public ?string $meta = null,
        public bool $isNew = false,
    ) {}
}
