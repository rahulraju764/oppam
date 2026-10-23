<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\DecidesBrokerKyc;
use App\Domain\Broker\KycQueue;
use App\Enums\BrokerKycStatus;
use App\Enums\BureauRole;
use App\Enums\KycSubmissionStatus;
use App\Models\AdminUser;
use App\Models\BrokerKyc;
use App\Models\BrokerStaff;
use App\Notifications\Broker\BrokerKycDecided;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 KYC reject: the submission is REJECTED with a reason the Owner reads; the bureau keeps its
 * status (KYC_PENDING) with KYC REJECTED, so the Owner can correct and resend. Needs `brokers.kyc`;
 * audited; the Owner is notified.
 */
final class RejectBrokerKyc
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
    public function handle(AdminUser $admin, BrokerKyc $kyc, string $reason): void
    {
        $this->authorizeKyc($admin);
        $reason = $this->validatedReason($reason);

        $broker = DB::transaction(function () use ($admin, $kyc, $reason) {
            [$broker, $kyc] = $this->lockPendingKyc($kyc);
            $before = ['kyc_status' => $broker->kyc_status->value];

            $kyc->forceFill(['status' => KycSubmissionStatus::Rejected, 'reviewed_by_admin_id' => $admin->id, 'reviewed_at' => now(), 'rejection_reason' => $reason])->save();
            $broker->forceFill(['kyc_status' => BrokerKycStatus::Rejected])->save();

            $this->audit->record('brokers.kyc_rejected', $broker, $before, ['kyc_status' => BrokerKycStatus::Rejected->value],
                reason: $reason, actor: $admin, subjectLabel: $broker->code);
            $this->queue->announce();

            return $broker;
        });

        BrokerStaff::query()->where('broker_id', $broker->id)->where('role', BureauRole::Owner)->with('user')->first()
            ?->user?->notify(new BrokerKycDecided(BrokerKycDecided::KYC_REJECTED, $reason));
    }
}
