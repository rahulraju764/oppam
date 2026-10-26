<?php

declare(strict_types=1);

namespace App\Actions\Broker\Staff;

use App\Actions\Auth\SendOtp;
use App\Actions\Auth\SignInMember;
use App\Actions\Auth\VerifyOtp;
use App\Domain\Broker\StaffSeats;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Exceptions\Broker\StaffRefused;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\MemberPassword;
use App\Support\Facades\Feature;
use App\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Accepting a staff invitation (§11A B.8: "sets password → verifies mobile by OTP → active";
 * decisions 2026-10-26). The invitation is found only by the sha256 of the link token, and must be
 * unaccepted, unexpired (72 h) and for a bureau that is still ACTIVE.
 *
 *  - start(): validates the password (the member password rules) and texts a STAFF_INVITE code to
 *    the INVITED mobile — never one typed here. A number with no login gets an unverified BROKER
 *    login to receive it (as ApplyAsBroker); an existing reusable login (unfinished acceptance, or a
 *    login this bureau deactivated) is not touched until the code is right.
 *  - verify(): the code must have been issued to that login; then in one transaction (invitation +
 *    bureau locked) a seat is re-checked, the login gets the new password and a verified mobile, the
 *    `broker_staff` row is created — or the deactivated one reactivated — ACTIVE with the
 *    invitation's role, scope and name, no switch-offs, and the invitation is used up. Audited with
 *    the new staff user; then they are signed in (→ /broker).
 */
final class AcceptStaffInvite
{
    public function __construct(
        private readonly SendOtp $sendOtp,
        private readonly VerifyOtp $verifyOtp,
        private readonly SignInMember $signIn,
        private readonly StaffSeats $seats,
        private readonly AuditLogger $audit,
    ) {}

    /** The open invitation behind this link token, with its bureau — or null (any reason: one generic answer). */
    public function find(string $token): ?BrokerStaffInvitation
    {
        if (! Feature::active(Flag::BrokerStaff) || preg_match('/^[A-Za-z0-9]{'.InviteStaff::TOKEN_LENGTH.'}$/', $token) !== 1) {
            return null;
        }

        $invitation = BrokerStaffInvitation::query()->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->where('expires_at', '>', now())
            ->with('broker')->first();

        return $invitation?->broker?->status === BrokerStatus::Active && $invitation->broker->isVerifiedAndActive() ? $invitation : null;
    }

    /**
     * Step 1: the password, then the code to the invited mobile.
     *
     * @return string the password hash to keep (server side) until verify()
     *
     * @throws StaffRefused the link is no longer valid, or the number can no longer be used
     * @throws ValidationException the password
     * @throws OtpThrottled
     */
    public function start(string $token, string $password, string $confirmation, string $ip): string
    {
        $invitation = $this->find($token) ?? throw StaffRefused::invitationInvalid();

        Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
            'password' => ['required', 'string', 'max:72', 'confirmed', MemberPassword::rule()],
        ], [], ['password' => __('password')])->validate();

        $hash = Hash::make($password);
        $login = DB::transaction(fn (): User => $this->receivingLogin($invitation, $hash));

        $this->sendOtp->handle(PhoneNumber::fromE164($invitation->phone), OtpPurpose::StaffInvite, $ip, $login);

        return $hash;
    }

    /**
     * Step 2: the code; the login becomes an ACTIVE staff member and is signed in.
     *
     * @throws StaffRefused the link is no longer valid, no seat
     * @throws OtpInvalid
     */
    public function verify(string $token, string $code, string $passwordHash, Request $request): User
    {
        $invitation = $this->find($token) ?? throw StaffRefused::invitationInvalid();
        $challenge = $this->verifyOtp->handle(PhoneNumber::fromE164($invitation->phone), OtpPurpose::StaffInvite, $code);

        $user = DB::transaction(function () use ($invitation, $challenge, $passwordHash): User {
            $locked = BrokerStaffInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();
            if ($locked === null || $locked->accepted_at !== null || $locked->expires_at->isPast()) {
                throw StaffRefused::invitationInvalid();
            }

            $broker = Broker::query()->whereKey($locked->broker_id)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('phone', $locked->phone)->lockForUpdate()->first();
            if ($user === null || $challenge->user_id !== $user->id) {
                throw OtpInvalid::wrongOrExpired();
            }

            // Re-checked under the lock: never move a login that belongs to another bureau (or is an Owner).
            $existing = BrokerStaff::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($user->role !== UserRole::Broker || $user->status !== UserStatus::Active || ($existing !== null && ($existing->broker_id !== $broker->id
                || $existing->status !== BureauStaffStatus::Deactivated || $existing->isOwner()))) {
                throw StaffRefused::numberNotAllowed();
            }

            if ($this->seats->activeStaff($broker) >= $this->seats->limit($broker)) {
                throw StaffRefused::noSeat($this->seats->limit($broker));
            }

            $user->forceFill([
                'password' => $passwordHash,   // already hashed: the cast keeps it as is
                'phone_verified_at' => $user->phone_verified_at ?? now(),
            ])->save();

            $staff = $existing ?? new BrokerStaff;
            $staff->forceFill([
                'broker_id' => $broker->id,
                'user_id' => $user->id,
                'name' => $locked->name,
                'role' => $locked->role,
                'profile_scope' => $locked->profile_scope,
                'disabled_permissions' => null,
                'status' => BureauStaffStatus::Active,
                'invited_by_user_id' => BrokerStaff::query()->where('broker_id', $broker->id)->where('role', BureauRole::Owner->value)->value('user_id'),
            ])->save();

            $locked->forceFill(['accepted_at' => now()])->save();

            $this->audit->record('broker.staff_joined', $staff, after: [
                'broker' => $broker->code, 'role' => $locked->role->value, 'scope' => $locked->profile_scope->value,
            ], actor: $user, subjectLabel: $staff->code);

            return $user;
        });

        $this->signIn->handle($user, LoginMethod::Registration, remember: false, request: $request);

        return $user;
    }

    /**
     * The login that receives the code: a new unverified BROKER login for a number with none, or the
     * existing reusable one (left untouched until verify()). Anything else is refused generically.
     */
    private function receivingLogin(BrokerStaffInvitation $invitation, string $hash): User
    {
        $holder = User::withTrashed()->where('phone', $invitation->phone)->lockForUpdate()->first();

        if ($holder === null) {
            $user = new User;
            $user->forceFill([
                'phone' => $invitation->phone,
                'password' => $hash,
                'role' => UserRole::Broker,
                'status' => UserStatus::Active,
                'phone_verified_at' => null,
            ])->save();

            return $user;
        }

        $row = BrokerStaff::query()->where('user_id', $holder->id)->first();
        $reusable = ! $holder->trashed() && $holder->role === UserRole::Broker && $holder->status === UserStatus::Active
            && ($row === null
                ? ! $holder->hasVerifiedPhone()
                : $row->broker_id === $invitation->broker_id && $row->status === BureauStaffStatus::Deactivated && ! $row->isOwner());

        return $reusable ? $holder : throw StaffRefused::numberNotAllowed();
    }
}
