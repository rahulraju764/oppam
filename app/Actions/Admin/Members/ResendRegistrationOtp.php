<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Actions\Auth\SendOtp;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\ValueObjects\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin "resend OTP" (A03 quick action, P1.7b): send a fresh registration code to a member who
 * never verified their mobile (support call: "I didn't get the code"). Goes through SendOtp, so
 * every send limit applies (per number and per IP — the admin's); the code goes only to the
 * member's own number and is never shown to the admin. Needs `members.edit` and a typed reason;
 * audited.
 */
final class ResendRegistrationOtp
{
    use ChangesMemberState;

    public function __construct(
        private readonly SendOtp $sendOtp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict
     */
    public function handle(AdminUser $admin, User $member, string $reason, string $ip): void
    {
        Gate::forUser($admin)->authorize('members.edit');
        $reason = $this->validatedReason($reason);
        $member = $this->lockMember($member);   // members only (outside a transaction: a plain re-read)

        if ($member->trashed() || $member->status !== UserStatus::Active) {
            throw MemberStateConflict::notActive();
        }

        if ($member->hasVerifiedPhone()) {
            throw MemberStateConflict::notUnverified();
        }

        try {
            $this->sendOtp->handle(PhoneNumber::fromE164($member->phone), OtpPurpose::Register, $ip, $member);
        } catch (OtpThrottled $throttled) {
            throw MemberStateConflict::otpThrottled($throttled->getMessage());
        }

        $this->audit->record('members.otp_resent', $member, reason: $reason, actor: $admin,
            subjectLabel: $member->profile()->withTrashed()->value('code'));
    }
}
