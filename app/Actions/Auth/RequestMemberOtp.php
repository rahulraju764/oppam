<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use InvalidArgumentException;

/**
 * "Send me a code" for LOGIN or PASSWORD_RESET (PRD §8.2 methods 1 and 3). The caller always
 * shows the same "If this number is registered, we've sent a code" screen (R-M01-4): a code is
 * texted only to a number that belongs to a phone-verified account, but the send limits are
 * spent either way. Blocked accounts do get the code — they learn their status only after
 * proving the number (R-M01-5).
 */
final class RequestMemberOtp
{
    public function __construct(private readonly SendOtp $sendOtp) {}

    /** @throws OtpThrottled */
    public function handle(PhoneNumber $phone, OtpPurpose $purpose, string $ip): void
    {
        if (! in_array($purpose, [OtpPurpose::Login, OtpPurpose::PasswordReset], true)) {
            throw new InvalidArgumentException('Only login and password-reset codes are requested here.');
        }

        $user = User::query()->where('phone', $phone->e164())->whereNotNull('phone_verified_at')->first();

        $this->sendOtp->handle($phone, $purpose, $ip, $user);
    }
}
