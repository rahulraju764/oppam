<?php

declare(strict_types=1);

namespace App\Actions\Broker;

use App\Actions\Auth\SendOtp;
use App\Contracts\SmsGateway;
use App\Data\Auth\RegistrationResult;
use App\Data\Broker\BrokerApplicationData;
use App\Domain\Broker\PanNumber;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Enums\OtpPurpose;
use App\Enums\ProfileScope;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Auth\OtpThrottled;
use App\Exceptions\Auth\RegistrationFailed;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Broker\BrokerCodeGenerator;
use App\Support\Facades\Feature;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Self-application of a broker (PRD §11A B.3, B.11 `/broker/apply`, behind `broker.self_apply`):
 * creates a BROKER login (mobile not yet verified), the bureau (APPLIED, code BRK… from the
 * sequence — R-A07-1) and its OWNER `broker_staff` row (INVITED until KYC approval — decisions
 * 2026-10-23), then texts the REGISTER code; the applicant finishes on the normal /verify-otp
 * screen (CompleteRegistration), which signs them in and sends them to /broker.
 *
 * - R-M13-22 one mobile = one login: a number already on Oppam gets nothing created, the same
 *   code screen (no code works) and the owner an "account exists" SMS — exactly like member
 *   registration (R-M01-4). A number held only by an unfinished sign-up (never verified) is
 *   released.
 * - One PAN = one bureau (keyed hash); a second application with it is refused with a message,
 *   unless the holder never proved its mobile and is past the hold window (then it is released).
 * - Role, status, code, commission and seats come from the server only. Per-IP cap.
 * - A bureau an admin created in A07 (P7.8, decisions 2026-10-29) waits for its Owner: an
 *   application with that bureau's mobile is linked to it (code, commission and limits kept)
 *   instead of opening another. With `broker.self_apply` off only such an application is accepted,
 *   through /broker/apply?bureau=<code>, and only with the mobile on record. The mobile is still
 *   proved by OTP and KYC follows as usual (R-A07-2).
 */
final class ApplyAsBroker
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly BrokerCodeGenerator $codes,
        private readonly SendOtp $sendOtp,
        private readonly SmsGateway $sms,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws RegistrationFailed for the per-IP cap
     * @throws ValidationException when the PAN already belongs to a bureau
     */
    public function handle(BrokerApplicationData $data, string $ip, ?string $reservedCode = null): RegistrationResult
    {
        $selfApply = Feature::active(Flag::BrokerSelfApply);
        if (! $selfApply && $reservedCode === null) {
            abort(404);
        }

        $ipKey = 'broker-apply:ip:'.$ip;
        if ($this->limiter->hit($ipKey, 3600) > (int) config('oppam.broker.applications_per_ip_per_hour')) {
            throw RegistrationFailed::tooManyAttempts($this->limiter->availableIn($ipKey));
        }

        // Hash on every path (timing must not reveal a taken number — as RegisterMember).
        $passwordHash = Hash::make($data->password);
        $panHash = PanNumber::hash($data->pan);

        /** @var array{0: User|null, 1: User|null} $outcome [new login, existing holder] */
        $outcome = DB::transaction(function () use ($data, $passwordHash, $panHash, $selfApply, $reservedCode): array {
            $holder = User::withTrashed()->where('phone', $data->phone->e164())->lockForUpdate()->first();

            if ($holder !== null) {
                if (! $this->isReleasable($holder)) {
                    return [null, $holder];
                }

                $this->release($holder);
            }

            $reserved = Broker::query()->where('phone', $data->phone->e164())->where('status', BrokerStatus::Applied)
                ->whereNotNull('created_by_admin_id')->whereDoesntHave('staff')->lockForUpdate()->first();

            if (! $selfApply && ($reserved === null || $reserved->code !== $reservedCode)) {
                throw ValidationException::withMessages(['form.mobile' => __('Use the mobile number our team registered for this bureau.')]);
            }

            $panHolder = Broker::query()->where('pan_hash', $panHash)->lockForUpdate()->first();
            if ($panHolder !== null && $panHolder->id !== $reserved?->id) {
                $this->releasePan($panHolder);
            }

            $user = new User;
            $user->forceFill([
                'phone' => $data->phone->e164(),
                'email' => $data->email !== null && ! User::withTrashed()->where('email', $data->email)->exists() ? $data->email : null,
                'password' => $passwordHash,
                'role' => UserRole::Broker,
                'status' => UserStatus::Active,
                'phone_verified_at' => null,
            ])->save();

            if ($reserved !== null) {
                // Admin-created: code, terms and the details A07 entered stay as they are — the mobile
                // isn't proved yet, so an application only fills what A07 left blank (the PAN goes
                // with the application and is released with it).
                $broker = $reserved;
                $broker->forceFill([
                    'bureau_name' => $broker->bureau_name ?? $data->bureauName,
                    'email' => $broker->email ?? $user->email,
                    'district_id' => $broker->district_id ?? $data->districtId,
                    'pan_hash' => $panHash,
                    'pan_last4' => PanNumber::last4($data->pan),
                ])->save();
            } else {
                $broker = new Broker;
                $broker->forceFill([
                    'code' => $this->codes->next(),
                    'name' => $data->name,
                    'bureau_name' => $data->bureauName,
                    'phone' => $data->phone->e164(),
                    'email' => $user->email,
                    'district_id' => $data->districtId,
                    'status' => BrokerStatus::Applied,
                    'kyc_status' => BrokerKycStatus::NotSubmitted,
                    'pan_hash' => $panHash,
                    'pan_last4' => PanNumber::last4($data->pan),
                    'staff_seat_limit' => Settings::int(SettingKey::BrokerStaffSeatsDefault),
                ])->save();
            }

            $owner = new BrokerStaff;
            $owner->forceFill([
                'broker_id' => $broker->id,
                'user_id' => $user->id,
                'role' => BureauRole::Owner,
                'profile_scope' => ProfileScope::All,
                'status' => BureauStaffStatus::Invited,
            ])->save();

            $this->audit->record('broker.applied', $broker, after: ['status' => BrokerStatus::Applied->value, 'admin_created' => $reserved !== null],
                actor: $user, subjectLabel: $broker->code);

            return [$user, null];
        });

        [$user, $holder] = $outcome;

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

    /**
     * Only a sign-up that never proved its mobile may be released: a plain member self-registration,
     * or an earlier bureau application still APPLIED. Never a verified, deleted, blocked or working login.
     */
    private function isReleasable(User $holder): bool
    {
        if ($holder->hasVerifiedPhone() || $holder->trashed() || $holder->status !== UserStatus::Active) {
            return false;
        }

        if ($holder->role === UserRole::Member) {
            return true;
        }

        $staff = BrokerStaff::query()->where('user_id', $holder->id)->with('broker')->first();

        return $staff === null || ($staff->isOwner() && $staff->broker?->status === BrokerStatus::Applied);
    }

    /**
     * A PAN is not a secret, so an application whose mobile was never proved must not keep it
     * from the real bureau: after `oppam.broker.unverified_pan_hold_minutes` it is released
     * like an unfinished sign-up. A bureau with a proved Owner keeps its PAN.
     *
     * @throws ValidationException while the PAN is held
     */
    private function releasePan(Broker $holder): void
    {
        $owner = BrokerStaff::query()->where('broker_id', $holder->id)->where('role', BureauRole::Owner)->first();
        $ownerUser = $owner !== null ? User::withTrashed()->whereKey($owner->user_id)->lockForUpdate()->first() : null;

        if ($ownerUser === null || ! $this->isReleasable($ownerUser)) {
            throw ValidationException::withMessages(['form.pan' => __('This PAN is already linked to a bureau on Oppam. Please contact our support team.')]);
        }

        if ($holder->created_at->gt(now()->subMinutes((int) config('oppam.broker.unverified_pan_hold_minutes')))) {
            throw ValidationException::withMessages(['form.pan' => __('An application with this PAN is waiting for its mobile number to be confirmed. Please try again in :minutes minutes or contact our support team.', [
                'minutes' => (int) config('oppam.broker.unverified_pan_hold_minutes'),
            ])]);
        }

        $this->release($ownerUser);
    }

    private function release(User $holder): void
    {
        $staff = BrokerStaff::query()->where('user_id', $holder->id)->first();
        if ($staff !== null) {
            $brokerId = $staff->broker_id;
            $staff->delete();

            // An admin-created bureau stays, waiting for its Owner again; a self-application goes.
            Broker::query()->whereKey($brokerId)->whereNull('created_by_admin_id')->delete();
            Broker::query()->whereKey($brokerId)->update(['pan_hash' => null, 'pan_last4' => null, 'updated_at' => now()]);
        }

        Profile::withTrashed()->where('user_id', $holder->id)->forceDelete();
        $holder->forceDelete();
    }

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
}
