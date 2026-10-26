<?php

declare(strict_types=1);

namespace App\Actions\Broker\Staff;

use App\Contracts\SmsGateway;
use App\Data\Broker\StaffInviteData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\StaffSeats;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Enums\ProfileScope;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Broker\StaffRefused;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Feature;
use App\ValueObjects\PhoneNumber;
use Closure;
use Illuminate\Cache\RateLimiter;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Owner invites a staff member (§11A B.8, R-M13-22 / R-M13-24 / R-M13-25; decisions 2026-10-26):
 * name, mobile, role (Manager / Data Entry / Telecaller — never a second Owner) and profile scope.
 *
 *  - `staff.manage` (Owner only), the `broker.staff` flag, an ACTIVE KYC-verified bureau;
 *  - the mobile must not be on any Oppam login (one mobile = one login) — except a login this
 *    bureau deactivated (re-invited, it is reactivated on acceptance) or an unfinished acceptance
 *    (an unverified bureau login with no staff row). The refusal never says whose number it is;
 *  - a free seat, decided under a lock on the bureau row so two invites can't take the last seat;
 *    an earlier open invitation to the same number is replaced (it doesn't hold a second seat);
 *  - only the sha256 of the 64-character link token is stored; the link is valid 72 h, once, and
 *    is texted after the commit (the plain token exists only in that SMS);
 *  - audited with the Owner's user id; capped per Owner per hour.
 */
final class InviteStaff
{
    public const TOKEN_LENGTH = 64;

    public const VALID_HOURS = 72;

    private const PER_HOUR = 20;

    public function __construct(
        private readonly BureauGate $gate,
        private readonly StaffSeats $seats,
        private readonly SmsGateway $sms,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without `staff.manage`
     * @throws StaffRefused flag off, bureau not verified, no seat, number not allowed
     * @throws ValidationException name / mobile / role / scope
     */
    public function handle(User $owner, StaffInviteData $data): BrokerStaffInvitation
    {
        $staff = $this->gate->authorize($owner, BureauPermission::StaffManage);
        /** @var Broker $broker */
        $broker = $staff->broker;

        if (! Feature::active(Flag::BrokerStaff)) {
            throw StaffRefused::notEnabled();
        }

        if (! $broker->isVerifiedAndActive()) {
            throw StaffRefused::bureauNotVerified();
        }

        $phone = $this->validated($data);

        $key = 'broker-staff-invites:'.$owner->id;
        if ($this->limiter->tooManyAttempts($key, self::PER_HOUR)) {
            throw ValidationException::withMessages(['phone' => __('You have sent many invitations in the last hour. Please try again later.')]);
        }

        $token = Str::random(self::TOKEN_LENGTH);

        $invitation = DB::transaction(function () use ($owner, $broker, $data, $phone, $token): BrokerStaffInvitation {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();

            $this->assertNumberAllowed($locked, $phone);

            // A new invitation to the same number replaces the open one (no second seat, one valid link).
            BrokerStaffInvitation::query()->where('broker_id', $locked->id)->where('phone', $phone->e164())
                ->whereNull('accepted_at')->where('expires_at', '>', now())
                ->update(['expires_at' => now(), 'updated_at' => now()]);

            if ($this->seats->free($locked) < 1) {
                throw StaffRefused::noSeat($this->seats->limit($locked));
            }

            $invitation = new BrokerStaffInvitation;
            $invitation->forceFill([
                'broker_id' => $locked->id,
                'phone' => $phone->e164(),
                'name' => trim($data->name),
                'role' => BureauRole::from($data->role),
                'profile_scope' => ProfileScope::from($data->scope),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(self::VALID_HOURS),
            ])->save();

            $this->audit->record('broker.staff_invited', $invitation, after: [
                'broker' => $locked->code, 'role' => $data->role, 'scope' => $data->scope, 'phone' => $phone->e164(),   // AuditLogger masks it
            ], actor: $owner, subjectLabel: $phone->masked());

            return $invitation;
        });

        $this->limiter->hit($key, 3600);
        $this->text($phone, $broker, $token);

        return $invitation;
    }

    public static function telecallerScopeMessage(): string
    {
        return __('Telecallers see only the clients assigned to them.');
    }

    /** The link texted to the invitee. */
    public static function link(string $token): string
    {
        return route('broker.staff.accept', ['token' => $token]);
    }

    private function validated(StaffInviteData $data): PhoneNumber
    {
        $phone = PhoneNumber::tryParse($data->phone);

        Validator::make($data->toArray(), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($phone): void {
                if ($phone === null) {
                    $fail(__('Enter a valid mobile number.'));
                }
            }],
            'role' => ['required', Rule::in([BureauRole::Manager->value, BureauRole::DataEntry->value, BureauRole::Telecaller->value])],
            'scope' => ['required', Rule::enum(ProfileScope::class), function (string $attribute, mixed $value, Closure $fail) use ($data): void {
                // B.2: a Telecaller's `profiles.view` is "assigned only" — the role's ceiling (R-M13-19).
                if ($data->role === BureauRole::Telecaller->value && $value !== ProfileScope::Assigned->value) {
                    $fail(self::telecallerScopeMessage());
                }
            }],
        ], [], ['name' => __('name'), 'phone' => __('mobile number'), 'role' => __('role'), 'scope' => __('profile access')])->validate();

        /** @var PhoneNumber $phone */
        return $phone;
    }

    /** R-M13-22: one mobile = one Oppam login. */
    private function assertNumberAllowed(Broker $broker, PhoneNumber $phone): void
    {
        $holder = User::withTrashed()->where('phone', $phone->e164())->lockForUpdate()->first();

        if ($holder === null) {
            return;
        }

        if ($holder->trashed() || $holder->role !== UserRole::Broker || $holder->status !== UserStatus::Active) {
            throw StaffRefused::numberNotAllowed();
        }

        $row = BrokerStaff::query()->where('user_id', $holder->id)->first();

        $reusable = $row === null
            ? ! $holder->hasVerifiedPhone()                                                        // an unfinished acceptance
            : $row->broker_id === $broker->id && $row->status === BureauStaffStatus::Deactivated && ! $row->isOwner();

        if (! $reusable) {
            throw StaffRefused::numberNotAllowed();
        }
    }

    /** After the response, like the OTP SMS; a delivery failure is logged (masked), the invite stays valid. */
    private function text(PhoneNumber $phone, Broker $broker, string $token): void
    {
        $sms = $this->sms;
        $bureau = $broker->displayName();
        $link = self::link($token);

        defer(static function () use ($sms, $phone, $bureau, $link): void {
            try {
                $sms->sendNotification($phone, 'staff_invite', [$bureau, $link]);
            } catch (SmsDeliveryFailed $e) {
                Log::warning('Staff invitation SMS failed', ['phone' => $phone->masked(), 'reason' => $e->reason]);
            }
        });
    }
}
