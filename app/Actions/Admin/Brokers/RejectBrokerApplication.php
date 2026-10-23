<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\DecidesBrokerKyc;
use App\Domain\Broker\KycQueue;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
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
 * A07 "reject application" (B.3 APPLIED / KYC_PENDING → REJECTED): the bureau is closed with a
 * reason, a pending KYC submission is rejected with it, and its logins are deactivated — they are
 * signed out on their next request (broker.active). Only a bureau that never became ACTIVE can be
 * rejected this way (an active one is suspended / deactivated in A07, P7.8). Needs `brokers.kyc`;
 * audited; the Owner is told.
 */
final class RejectBrokerApplication
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
    public function handle(AdminUser $admin, Broker $broker, string $reason): void
    {
        $this->authorizeKyc($admin);
        $reason = $this->validatedReason($reason);

        $owners = DB::transaction(function () use ($admin, $broker, $reason) {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [BrokerStatus::Applied, BrokerStatus::KycPending], true)) {
                throw ValidationException::withMessages(['reason' => __('Only an application that is still waiting can be rejected.')]);
            }

            $before = ['status' => $locked->status->value, 'kyc_status' => $locked->kyc_status->value];
            BrokerKyc::query()->where('broker_id', $locked->id)->where('status', KycSubmissionStatus::Pending)
                ->update(['status' => KycSubmissionStatus::Rejected->value, 'reviewed_by_admin_id' => $admin->id, 'reviewed_at' => now(), 'rejection_reason' => $reason, 'updated_at' => now()]);
            $locked->forceFill([
                'status' => BrokerStatus::Rejected,
                'kyc_status' => $locked->kyc_status === BrokerKycStatus::Pending ? BrokerKycStatus::Rejected : $locked->kyc_status,
                'status_reason' => $reason,
            ])->save();

            $staff = BrokerStaff::query()->where('broker_id', $locked->id)->with('user')->get();
            BrokerStaff::query()->where('broker_id', $locked->id)->update(['status' => BureauStaffStatus::Deactivated->value, 'updated_at' => now()]);

            $this->audit->record('brokers.application_rejected', $locked, $before, ['status' => BrokerStatus::Rejected->value],
                reason: $reason, actor: $admin, subjectLabel: $locked->code);
            $this->queue->announce();

            return $staff->filter(fn (BrokerStaff $s): bool => $s->isOwner());
        });

        $owners->each(fn (BrokerStaff $owner) => $owner->user?->notify(new BrokerKycDecided(BrokerKycDecided::APPLICATION_REJECTED, $reason)));
    }
}
