<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Payouts;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Enums\ReferralStatus;
use App\Events\Broker\PayoutPaid;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use App\Models\BrokerReferral;
use App\Models\BrokerStaff;
use App\Notifications\Broker\PayoutPaid as PayoutPaidNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record the bank transfer for one bureau's line of an APPROVED run (§11A B.10 "mark paid with
 * UTR"). Under row locks on the run and the line:
 *  - the bureau must still be payable (ACTIVE, KYC-verified, not held — R-A07-2); otherwise roll
 *    the line forward instead (RollPayoutLineForward);
 *  - the UTR is upper-cased, 6–30 letters / digits, and never used twice;
 *  - the line becomes PAID; its commissions — and those of earlier lines whose balance rolled into
 *    it — move onto this line and become PAID (a row voided before payment stays VOID here, so the
 *    next run claws it back — B.19); clawbacks of those earlier lines move here too;
 *  - when no line is left to pay the run is PAID.
 * After the commit the Owner gets the live `payout.paid` and a notification (B.15). Needs
 * `brokers.payout`; audited.
 */
final class MarkPayoutPaid
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException bad UTR
     * @throws ModelNotFoundException<BrokerPayoutItem> no line for that bureau in this run
     * @throws PayoutRefused
     */
    public function handle(AdminUser $admin, BrokerPayoutRun $run, string $brokerCode, string $utr): BrokerPayoutItem
    {
        $this->authorizeFor($admin, 'brokers.payout');
        $utr = strtoupper(trim($utr));

        if (preg_match('/^[A-Z0-9]{6,30}$/', $utr) !== 1) {
            throw ValidationException::withMessages(['utr' => __('Enter the bank’s UTR / reference: 6 to 30 letters and digits.')]);
        }

        try {
            $item = DB::transaction(function () use ($admin, $run, $brokerCode, $utr): BrokerPayoutItem {
                $lockedRun = BrokerPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                $broker = Broker::query()->where('code', $brokerCode)->lockForUpdate()->firstOrFail();
                $item = BrokerPayoutItem::query()->where('run_id', $lockedRun->id)->where('broker_id', $broker->id)->lockForUpdate()->firstOrFail();

                if ($lockedRun->status !== PayoutRunStatus::Approved) {
                    throw $lockedRun->status === PayoutRunStatus::Paid ? PayoutRefused::lineNotPayable() : PayoutRefused::notApproved();
                }
                if ($item->status !== PayoutItemStatus::Pending) {
                    throw PayoutRefused::lineNotPayable();
                }
                if (! $broker->isPayable()) {
                    throw PayoutRefused::bureauNotPayable($broker->code);
                }
                if (BrokerPayoutItem::query()->where('utr', $utr)->exists()) {
                    throw PayoutRefused::utrUsed();
                }

                $lines = $this->withEarlierLines($item);
                BrokerReferral::query()->whereIn('payout_item_id', $lines)->update(['payout_item_id' => $item->id, 'updated_at' => now()]);
                BrokerReferral::query()->whereIn('clawed_back_item_id', $lines)->update(['clawed_back_item_id' => $item->id, 'updated_at' => now()]);
                BrokerReferral::query()->where('payout_item_id', $item->id)->where('status', ReferralStatus::Earned)
                    ->update(['status' => ReferralStatus::Paid->value, 'updated_at' => now()]);

                $item->forceFill(['status' => PayoutItemStatus::Paid, 'utr' => $utr, 'paid_at' => now()])->save();

                if (! BrokerPayoutItem::query()->where('run_id', $lockedRun->id)->where('status', PayoutItemStatus::Pending)->exists()) {
                    $lockedRun->forceFill(['status' => PayoutRunStatus::Paid, 'paid_at' => now()])->save();
                }

                $this->audit->record('brokers.payout_paid', $item, ['status' => PayoutItemStatus::Pending->value],
                    ['status' => PayoutItemStatus::Paid->value, 'utr' => $utr, 'net_paise' => $item->net_paise, 'run' => $lockedRun->code, 'broker' => $broker->code],
                    actor: $admin, subjectLabel: $broker->code);

                return $item->setRelation('run', $lockedRun)->setRelation('broker', $broker);
            });
        } catch (UniqueConstraintViolationException) {
            throw PayoutRefused::utrUsed();   // the same UTR typed on two lines at once: the unique index decides
        }

        $this->tellOwner($item);

        return $item;
    }

    /**
     * This line and every earlier line whose balance rolled into it (directly or along a chain).
     *
     * @return list<string>
     */
    private function withEarlierLines(BrokerPayoutItem $item): array
    {
        $all = [$item->id];
        $frontier = [$item->id];

        while ($frontier !== []) {
            $frontier = BrokerPayoutItem::query()->whereIn('rolled_into_item_id', $frontier)->pluck('id')->all();
            $all = [...$all, ...$frontier];
        }

        return $all;
    }

    private function tellOwner(BrokerPayoutItem $item): void
    {
        $runCode = (string) $item->run?->code;
        $amount = $item->net()->formatWithSymbol();

        PayoutPaid::dispatch($item->broker_id, $runCode, $amount);

        BrokerStaff::query()->where('broker_id', $item->broker_id)->where('role', BureauRole::Owner)
            ->where('status', BureauStaffStatus::Active)->with('user')->get()
            ->each(fn (BrokerStaff $owner) => $owner->user?->notify(new PayoutPaidNotification($runCode, $amount)));
    }
}
