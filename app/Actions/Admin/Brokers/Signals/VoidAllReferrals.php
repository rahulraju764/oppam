<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Signals;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 "void all" (§11A B.12 "broker referring themselves under another identity → suspend, void
 * all"): every commission row of the bureau that still counts — PENDING (never earns), EARNED
 * (never paid) and PAID (taken back as a clawback by the next payout run, B.19) — becomes VOID with
 * reason FRAUD. Attribution stays (R-M13-3: it is history). Suspending is a separate action. Needs
 * `brokers.payout`; typed reason; audited with the counts.
 */
final class VoidAllReferrals
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{pending: int, earned: int, paid: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Broker $broker, string $reason): array
    {
        $this->authorizeFor($admin, 'brokers.payout');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $broker, $reason): array {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            $rows = BrokerReferral::query()->where('broker_id', $locked->id)
                ->whereIn('status', [ReferralStatus::Pending, ReferralStatus::Earned, ReferralStatus::Paid])
                ->lockForUpdate()->get(['id', 'status']);

            $counts = [
                'pending' => $rows->where('status', ReferralStatus::Pending)->count(),
                'earned' => $rows->where('status', ReferralStatus::Earned)->count(),
                'paid' => $rows->where('status', ReferralStatus::Paid)->count(),
            ];

            BrokerReferral::query()->whereIn('id', $rows->pluck('id'))->update([
                'status' => ReferralStatus::Void->value, 'voided_at' => now(), 'void_reason' => ReferralVoidReason::Fraud->value, 'updated_at' => now(),
            ]);

            $this->audit->record('brokers.referrals_voided', $locked, after: $counts, reason: $reason, actor: $admin, subjectLabel: $locked->code);

            return $counts;
        });
    }
}
