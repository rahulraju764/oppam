<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Payouts;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\PayoutRunStatus;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AdminUser;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use App\Models\BrokerReferral;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Discard a DRAFT payout run (a preview finance does not want): everything it claimed goes back —
 * commissions to the pool, clawbacks to "not clawed", carried balances to "not taken over" — and
 * the run and its lines are deleted. An approved run can't be discarded. Needs `brokers.payout`
 * and a reason; audited (the audit row keeps the run code and period).
 */
final class DiscardPayoutRun
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws PayoutRefused
     */
    public function handle(AdminUser $admin, BrokerPayoutRun $run, string $reason): void
    {
        $this->authorizeFor($admin, 'brokers.payout');
        $reason = $this->validatedReason($reason);

        DB::transaction(function () use ($admin, $run, $reason): void {
            $locked = BrokerPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutRunStatus::Draft) {
                throw PayoutRefused::notDraft();
            }

            $itemIds = BrokerPayoutItem::query()->where('run_id', $locked->id)->pluck('id')->all();

            BrokerReferral::query()->whereIn('payout_item_id', $itemIds)->update(['payout_item_id' => null, 'updated_at' => now()]);
            BrokerReferral::query()->whereIn('clawed_back_item_id', $itemIds)->update(['clawed_back_item_id' => null, 'updated_at' => now()]);
            BrokerPayoutItem::query()->whereIn('rolled_into_item_id', $itemIds)->update(['rolled_into_item_id' => null, 'updated_at' => now()]);

            $this->audit->record('brokers.payout_run_discarded', $locked, [
                'period' => $locked->period_start->toDateString().'..'.$locked->period_end->toDateString(),
                'lines' => count($itemIds),
            ], reason: $reason, actor: $admin, subjectLabel: $locked->code);

            BrokerPayoutItem::query()->whereKey($itemIds)->delete();
            $locked->delete();
        });
    }
}
