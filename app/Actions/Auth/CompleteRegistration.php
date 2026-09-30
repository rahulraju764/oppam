<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of registration (M01): the REGISTER code proves the mobile, the account becomes
 * phone-verified and the member is signed in. Until this succeeds the account cannot reach the
 * wizard (acceptance "Registration without OTP verification cannot reach the wizard").
 *
 * $pendingUserId comes from the server session (never the browser) and is null when the number
 * already had an account — then no code can succeed, with the same error as a wrong code.
 */
final class CompleteRegistration
{
    public function __construct(
        private readonly VerifyOtp $verifyOtp,
        private readonly SignInMember $signIn,
    ) {}

    /**
     * @throws OtpInvalid
     * @throws LoginFailed when the account was blocked meanwhile
     */
    public function handle(PhoneNumber $phone, ?string $pendingUserId, string $code, Request $request): User
    {
        $challenge = $this->verifyOtp->handle($phone, OtpPurpose::Register, $code);

        // The code must have been issued to THIS pending account.
        if ($pendingUserId === null || $challenge->user_id !== $pendingUserId) {
            throw OtpInvalid::wrongOrExpired();
        }

        $user = DB::transaction(function () use ($pendingUserId): User {
            $user = User::query()->lockForUpdate()->findOrFail($pendingUserId);

            if ($user->phone_verified_at === null) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            return $user;
        });

        if (! $user->isActive()) {
            throw LoginFailed::accountBlocked();
        }

        $this->signIn->handle($user, LoginMethod::Registration, remember: false, request: $request);

        return $user;
    }
}
