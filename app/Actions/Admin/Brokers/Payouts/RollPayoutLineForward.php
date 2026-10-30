<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Payouts;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Don't pay one line of a run now — roll its whole balance (before TDS) forward to the next run
 * (decisions 2026-10-29): for a bureau suspended, put on hold or whose KYC lapsed after the run was
 * made, or a transfer that bounced before a UTR. The line becomes CARRIED_FORWARD and the run is PAID
 * once nothing is left to pay. Needs `brokers.payout` and a reason; audited.
 */
final class RollPayoutLineForward
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ModelNotFoundException<BrokerPayoutItem>
     * @throws PayoutRefused
     */
    public function handle(AdminUser $admin, BrokerPayoutRun $run, string $brokerCode, string $reason): BrokerPayoutItem
    {
        $this->authorizeFor($admin, 'brokers.payout');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $run, $brokerCode, $reason): BrokerPayoutItem {
            $lockedRun = BrokerPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $broker = Broker::query()->where('code', $brokerCode)->firstOrFail();
            $item = BrokerPayoutItem::query()->where('run_id', $lockedRun->id)->where('broker_id', $broker->id)->lockForUpdate()->firstOrFail();

            if ($lockedRun->status === PayoutRunStatus::Paid || $item->status !== PayoutItemStatus::Pending) {
                throw PayoutRefused::lineNotPayable();
            }

            $before = ['status' => $item->status->value, 'net_paise' => $item->net_paise, 'tds_paise' => $item->tds_paise];
            $item->forceFill([
                'status' => PayoutItemStatus::CarriedForward,
                'carried_forward_paise' => $item->net_paise + $item->tds_paise,
                'net_paise' => 0,
                'tds_paise' => 0,
            ])->save();

            if ($lockedRun->status === PayoutRunStatus::Approved
                && ! BrokerPayoutItem::query()->where('run_id', $lockedRun->id)->where('status', PayoutItemStatus::Pending)->exists()) {
                $lockedRun->forceFill(['status' => PayoutRunStatus::Paid, 'paid_at' => now()])->save();
            }

            $this->audit->record('brokers.payout_rolled_forward', $item, $before,
                ['status' => PayoutItemStatus::CarriedForward->value, 'carried_forward_paise' => $item->carried_forward_paise, 'run' => $lockedRun->code],
                reason: $reason, actor: $admin, subjectLabel: $broker->code);

            return $item;
        });
    }
}
