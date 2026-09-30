<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Models\User;
use App\ValueObjects\PhoneNumber;

/**
 * Outcome of RegisterMember. $user is the new (unverified) account, or null when the number
 * already belongs to an account — the caller must NOT behave differently (the visitor goes to
 * the same code screen; no code will work). $otpNotice is set when the send limit stopped the
 * code; the verify screen shows it and offers "Resend".
 */
final readonly class RegistrationResult
{
    public function __construct(
        public ?User $user,
        public PhoneNumber $phone,
        public ?string $otpNotice = null,
    ) {}
}
