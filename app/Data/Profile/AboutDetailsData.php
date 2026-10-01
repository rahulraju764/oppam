<?php

declare(strict_types=1);

namespace App\Data\Profile;

/**
 * Wizard step 6 — about me, lifestyle and photo visibility (M02 + M11). Photos themselves are
 * uploaded through the media flow (P1.4), not this data.
 */
final readonly class AboutDetailsData
{
    /** @param  list<string>  $hobbies */
    public function __construct(
        public ?string $about,
        public ?int $diet_option_id,
        public ?int $smoking_option_id,
        public ?int $drinking_option_id,
        public array $hobbies,
        public ?string $photo_visibility,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
