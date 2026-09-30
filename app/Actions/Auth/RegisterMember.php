<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Contracts\SmsGateway;
use App\Data\Auth\RegistrationData;
use App\Data\Auth\RegistrationResult;
use App\Enums\OtpPurpose;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Auth\OtpThrottled;
use App\Exceptions\Auth\RegistrationFailed;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\Models\Profile;
use App\Models\User;
use App\Services\Profile\ProfileCodeGenerator;
use App\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step 1 of member registration (M01 flow, F01): creates the account with an UNVERIFIED mobile
 * and a DRAFT profile holding the name, gender and (hero form) DOB, then texts the REGISTER code.
 * The member is not signed in until CompleteRegistration verifies the code.
 *
 * - R-M01-1: one account per mobile (and per email, if given).
 * - Registration never reveals that a number is registered (R-M01-4 extended to registration,
 *   owner decision 2026-09-29): when the number belongs to an account, nothing is created, the
 *   visitor sees the usual "enter the code" screen (no code works), the same send limits are
 *   spent, and the real owner gets an SMS "you already have an account — log in instead".
 *   An email already on another account is simply not attached (the member can add an email
 *   later) — no message either way.
 * - A number held by an account that never verified it is released: that ordinary ACTIVE member
 *   self-registration and its draft are removed, so typing someone else's number can't lock the
 *   real owner out. Suspended/banned or broker accounts are never released (isReleasable).
 * - Role and status are fixed here (MEMBER / ACTIVE) — never taken from input.
 * - Registrations per IP are capped (config) to stop account-creation floods.
 */
final class RegisterMember
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ProfileCodeGenerator $codes,
        private readonly SendOtp $sendOtp,
        private readonly SmsGateway $sms,
    ) {}

    /** @throws RegistrationFailed only for the per-IP registration cap */
    public function handle(RegistrationData $data, string $ip): RegistrationResult
    {
        $ipKey = 'register:ip:'.$ip;

        if ($this->limiter->hit($ipKey, 3600) > (int) config('oppam.auth.registrations_per_ip_per_hour')) {
            throw RegistrationFailed::tooManyAttempts($this->limiter->availableIn($ipKey));
        }

        $email = $data->email !== null ? Str::lower(trim($data->email)) : null;

        // Hash on EVERY path, before we know whether the number is taken: bcrypt is the slowest
        // step, and skipping it for taken numbers would reveal them by response time (P1.1 review).
        $passwordHash = Hash::make($data->password);

        /** @var array{0: User|null, 1: User|null} $outcome [new account, existing holder] */
        $outcome = DB::transaction(function () use ($data, $email, $passwordHash): array {
            $holder = User::withTrashed()->where('phone', $data->phone->e164())->lockForUpdate()->first();

            if ($holder !== null) {
                if (! self::isReleasable($holder)) {
                    return [null, $holder];
                }

                $this->removeUnverified($holder);
            }

            $user = new User;
            $user->forceFill([
                'phone' => $data->phone->e164(),
                'email' => $email !== null && $this->emailIsFree($email) ? $email : null,
                'password' => $passwordHash,   // already hashed: the cast keeps it as is
                'created_for' => $data->createdFor,
                'role' => UserRole::Member,
                'status' => UserStatus::Active,
                'phone_verified_at' => null,
            ])->save();

            $profile = new Profile;
            $profile->forceFill([
                'user_id' => $user->id,
                'code' => $this->codes->next(),
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'gender' => $data->gender,
                'dob' => $data->dob,
                'status' => ProfileStatus::Draft,
            ])->save();

            return [$user, null];
        });

        [$user, $holder] = $outcome;

        // Same limits either way; a code is texted only to a new account. If the send limit is hit
        // the verify screen shows the retry time and offers Resend later.
        try {
            $this->sendOtp->handle($data->phone, OtpPurpose::Register, $ip, $user);
        } catch (OtpThrottled $e) {
            $notice = $e->getMessage();
        }

        if ($holder !== null && $holder->hasVerifiedPhone() && ! isset($notice)) {
            $this->notifyExistingOwner($data->phone);
        }

        return new RegistrationResult($user, $data->phone, $notice ?? null);
    }

    /** Texted after the response, like the OTP, so both paths take the same time. */
    private function notifyExistingOwner(PhoneNumber $phone): void
    {
        $sms = $this->sms;

        defer(static function () use ($sms, $phone): void {
            try {
                $sms->sendAccountExistsNotice($phone);
            } catch (SmsDeliveryFailed $e) {
                Log::warning('Account-exists SMS failed', ['phone' => $phone->masked(), 'reason' => $e->reason]);
            }
        });
    }

    /** An email on another account is free only if that account is a releasable pending one (which then loses it). */
    private function emailIsFree(string $email): bool
    {
        $holder = User::withTrashed()->where('email', $email)->lockForUpdate()->first();

        if ($holder === null) {
            return true;
        }

        if (! self::isReleasable($holder)) {
            return false;
        }

        // An account that never proved its mobile can't keep an email address either.
        $holder->forceFill(['email' => null])->save();

        return true;
    }

    /**
     * Only a plain self-registration that never proved its mobile may be released: never a
     * verified, deleted, suspended/banned (ban evasion) or non-member (broker/staff) account.
     */
    private static function isReleasable(User $holder): bool
    {
        return ! $holder->hasVerifiedPhone()
            && ! $holder->trashed()
            && $holder->role === UserRole::Member
            && $holder->status === UserStatus::Active;
    }

    /** An account that never verified its mobile has nothing worth keeping: remove it for good. */
    private function removeUnverified(User $user): void
    {
        Profile::withTrashed()->where('user_id', $user->id)->forceDelete();
        $user->forceDelete();
    }
}
