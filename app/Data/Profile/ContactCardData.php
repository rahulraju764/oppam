<?php

declare(strict_types=1);

namespace App\Data\Profile;

/** Revealed contact details (R-M03-2) — only ever built by ViewContact after every check passed. */
final readonly class ContactCardData
{
    public function __construct(
        public string $phone,
        public ?string $alternatePhone,
        public ?string $email,
        public ?string $contactPerson,
        public ?string $relation,
        public ?string $convenientTime,
    ) {}
}
