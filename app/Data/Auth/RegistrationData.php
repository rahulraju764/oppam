<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\CreatedFor;
use App\Enums\Gender;
use App\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * A validated registration from the home hero (Public\QuickRegister) or /register (M01).
 * Built by the Livewire form after validation; RegisterMember trusts its shape, not its values
 * (uniqueness and limits are re-checked there).
 */
final readonly class RegistrationData
{
    public function __construct(
        public CreatedFor $createdFor,
        public string $firstName,
        public ?string $lastName,
        public Gender $gender,
        public ?CarbonImmutable $dob,
        public PhoneNumber $phone,
        public ?string $email,
        #[SensitiveParameter] public string $password,
    ) {}
}
