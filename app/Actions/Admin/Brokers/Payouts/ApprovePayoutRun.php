<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Payouts;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AdminUser;
use App\Models\BrokerPayoutRun;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Approve a DRAFT payout run (§11A B.10, R-A07-4, B.19 "two admins approve the same payout → row
 * lock; second sees already approved"). The run row is locked and re-read:
 *  - no line to pay above the second-approver threshold → one approval makes it APPROVED;
 *  - otherwise the first approval is recorded and a **different** admin gives the second
 *    (the run's creator may be the first — decisions 2026-10-29);
 *  - a run with nothing left to pay (every line rolled forward) is PAID at once.
 * Needs `brokers.payout`; audited. Returns the run as it now stands.
 */
final class ApprovePayoutRun
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws PayoutRefused already approved, or the same admin tries to give the second approval
     */
    public function handle(AdminUser $admin, BrokerPayoutRun $run): BrokerPayoutRun
    {
        $this->authorizeFor($admin, 'brokers.payout');

        return DB::transaction(function () use ($admin, $run): BrokerPayoutRun {
            $locked = BrokerPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutRunStatus::Draft) {
                throw PayoutRefused::alreadyApproved();
            }

            $needsSecond = $locked->needsSecondApprover();

            if ($locked->approved_by_admin_id === null) {
                $locked->forceFill(['approved_by_admin_id' => $admin->id])->save();
                $step = 'first';
            } elseif (! $needsSecond) {
                // First approval given while a second was needed; since then the threshold rose or the
                // big line was rolled forward — this approval completes the run instead of sticking it.
                $step = 'final';
            } elseif ($locked->approved_by_admin_id !== $admin->id) {
                $locked->forceFill(['second_approved_by_admin_id' => $admin->id])->save();
                $step = 'second';
            } else {
                throw PayoutRefused::secondApproverMustDiffer();
            }

            if (! $needsSecond || $step === 'second') {
                $nothingToPay = ! $locked->items()->where('status', PayoutItemStatus::Pending)->exists();
                $locked->forceFill([
                    'status' => $nothingToPay ? PayoutRunStatus::Paid : PayoutRunStatus::Approved,
                    'approved_at' => now(),
                    'paid_at' => $nothingToPay ? now() : null,
                ])->save();
            }

            $this->audit->record('brokers.payout_run_approved', $locked, ['status' => PayoutRunStatus::Draft->value],
                ['status' => $locked->status->value, 'step' => $step, 'second_approver_needed' => $needsSecond],
                actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }
}
