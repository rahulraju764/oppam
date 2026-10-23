<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\DecidesBrokerKyc;
use App\Domain\Broker\KycQueue;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\KycSubmissionStatus;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerKyc;
use App\Models\BrokerStaff;
use App\Notifications\Broker\BrokerKycDecided;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 KYC approve (PRD §11A B.3 "PAN verified → ACTIVE", B.14 ApproveBrokerKyc): the submission
 * is VERIFIED, the bureau ACTIVE + KYC VERIFIED, and its OWNER `broker_staff` row becomes ACTIVE (it was
 * created INVITED with the application — decisions 2026-10-23). Only a bureau still waiting (APPLIED /
 * KYC_PENDING, or ACTIVE re-verifying after a revocation) can be approved; a rejected, suspended
 * or deactivated one can't. Needs `brokers.kyc`; audited; the Owner is notified.
 */
final class ApproveBrokerKyc
{
    use DecidesBrokerKyc;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly KycQueue $queue,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, BrokerKyc $kyc): Broker
    {
        $this->authorizeKyc($admin);

        [$broker, $owner] = DB::transaction(function () use ($admin, $kyc): array {
            [$broker, $kyc] = $this->lockPendingKyc($kyc);

            if (! in_array($broker->status, [BrokerStatus::Applied, BrokerStatus::KycPending, BrokerStatus::Active], true)) {
                throw ValidationException::withMessages(['reason' => __('This bureau is :status and can’t be approved.', ['status' => strtolower($broker->status->label())])]);
            }

            // The OWNER row was created (INVITED) with the application; approval makes it ACTIVE. A
            // bureau without one (admin-created, P7.8) gets its owner login first — never guessed here.
            $owner = BrokerStaff::query()->where('broker_id', $broker->id)->where('role', BureauRole::Owner)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['reason' => __('The bureau has no owner login to activate.')]);

            $before = ['status' => $broker->status->value, 'kyc_status' => $broker->kyc_status->value, 'owner' => $owner->status->value];

            $kyc->forceFill(['status' => KycSubmissionStatus::Verified, 'reviewed_by_admin_id' => $admin->id, 'reviewed_at' => now(), 'rejection_reason' => null])->save();
            $broker->forceFill([
                'status' => BrokerStatus::Active,
                'kyc_status' => BrokerKycStatus::Verified,
                'activated_at' => $broker->activated_at ?? now(),
                'status_reason' => null,
            ])->save();
            $owner->forceFill(['status' => BureauStaffStatus::Active])->save();

            $this->audit->record('brokers.kyc_approved', $broker, $before,
                ['status' => BrokerStatus::Active->value, 'kyc_status' => BrokerKycStatus::Verified->value, 'owner' => BureauStaffStatus::Active->value],
                actor: $admin, subjectLabel: $broker->code);
            $this->queue->announce();

            return [$broker, $owner];
        });

        $owner->loadMissing('user')->user?->notify(new BrokerKycDecided(BrokerKycDecided::APPROVED));

        return $broker;
    }
}
