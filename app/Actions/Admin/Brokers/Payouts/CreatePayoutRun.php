<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Payouts;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Domain\Broker\PayoutCalculator;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Enums\ReferralStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use App\Models\BrokerReferral;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 "create run for a period" (§11A B.10, B.12; decisions 2026-10-29) — the DRAFT run is the
 * preview finance checks before approving. One open run at a time: while a run is not PAID a new
 * one is refused, so carried balances and clawbacks always come from finished runs. A period that
 * overlaps a PAID run needs an audited override reason (B.17).
 *
 * For every payable bureau (ACTIVE, KYC-verified, payouts not held — R-A07-2; the others' rows
 * wait for a later run) it claims, under row locks:
 *  - EARNED commissions earned by the end of the period (IST) and not in any line yet → gross;
 *  - commissions voided after a line that paid them was PAID, not clawed back yet → clawback (B.19);
 *  - the bureau's last CARRIED_FORWARD balance not taken over yet → carried in;
 * and writes one line through PayoutCalculator (under the minimum → CARRIED_FORWARD, R-A07-5).
 * A period with nothing to pay is refused. Needs `brokers.payout`; audited.
 */
final class CreatePayoutRun
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException bad dates, missing override reason, nothing to pay
     * @throws PayoutRefused another run is still open (or being created), or a paid run overlaps without a reason
     */
    public function handle(AdminUser $admin, string $periodStart, string $periodEnd, ?string $overrideReason = null): BrokerPayoutRun
    {
        $this->authorizeFor($admin, 'brokers.payout');
        [$start, $end] = $this->period($periodStart, $periodEnd);

        try {
            return Cache::lock('broker-payout-run:create', 30)->block(10, fn (): BrokerPayoutRun => DB::transaction(
                fn (): BrokerPayoutRun => $this->create($admin, $start, $end, $overrideReason),
            ));
        } catch (LockTimeoutException) {
            throw PayoutRefused::busy();   // another admin is creating a run right now
        }
    }

    private function create(AdminUser $admin, CarbonImmutable $start, CarbonImmutable $end, ?string $overrideReason): BrokerPayoutRun
    {
        $open = BrokerPayoutRun::query()->where('status', '!=', PayoutRunStatus::Paid)->lockForUpdate()->first();
        if ($open !== null) {
            throw PayoutRefused::runStillOpen($open->code);
        }

        $paidOverlap = BrokerPayoutRun::query()->where('period_start', '<=', $end->toDateString())
            ->where('period_end', '>=', $start->toDateString())->first();
        $reason = null;
        if ($paidOverlap !== null) {
            if ($overrideReason === null || trim($overrideReason) === '') {
                throw PayoutRefused::paidPeriodNeedsOverride($paidOverlap->code);
            }
            $reason = $this->validatedReason($overrideReason, 'overrideReason');
        }

        $run = new BrokerPayoutRun;
        $run->forceFill([
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'status' => PayoutRunStatus::Draft,
            'override_reason' => $reason,
            'created_by_admin_id' => $admin->id,
        ])->save();

        $calculator = new PayoutCalculator(
            Settings::int(SettingKey::BrokerPayoutMinimumPaise),
            Settings::int(SettingKey::BrokerPayoutSecondApproverPaise),
            Settings::int(SettingKey::BrokerPayoutTdsBps),
        );
        $earnedBy = $end->endOfDay()->utc();   // the period end is an IST date
        $totals = ['lines' => 0, 'payable' => 0, 'carried' => 0, 'net_paise' => 0];

        foreach ($this->payableBureaus() as $broker) {
            $earned = BrokerReferral::query()->where('broker_id', $broker->id)->where('status', ReferralStatus::Earned)
                ->whereNull('payout_item_id')->where('earned_at', '<=', $earnedBy)->lockForUpdate()->get(['id', 'commission_paise']);
            $clawbacks = BrokerReferral::query()->where('broker_id', $broker->id)->where('status', ReferralStatus::Void)
                ->whereNull('clawed_back_item_id')
                ->whereHas('payoutItem', fn ($item) => $item->where('status', PayoutItemStatus::Paid))
                ->lockForUpdate()->get(['id', 'commission_paise']);
            $carried = BrokerPayoutItem::query()->where('broker_id', $broker->id)->where('status', PayoutItemStatus::CarriedForward)
                ->whereNull('rolled_into_item_id')->lockForUpdate()->latest()->first();

            $line = $calculator->line(
                (int) $earned->sum('commission_paise'),
                (int) $clawbacks->sum('commission_paise'),
                $carried->carried_forward_paise ?? 0,
            );

            if ($line->isEmpty()) {
                continue;
            }

            $item = new BrokerPayoutItem;
            $item->forceFill([
                'run_id' => $run->id,
                'broker_id' => $broker->id,
                'gross_paise' => $line->grossPaise,
                'clawback_paise' => $line->clawbackPaise,
                'carried_in_paise' => $line->carriedInPaise,
                'tds_paise' => $line->tdsPaise,
                'carried_forward_paise' => $line->carriedForwardPaise,
                'net_paise' => $line->netPaise,
                'status' => $line->payable ? PayoutItemStatus::Pending : PayoutItemStatus::CarriedForward,
            ])->save();

            BrokerReferral::query()->whereKey($earned->modelKeys())->update(['payout_item_id' => $item->id, 'updated_at' => now()]);
            BrokerReferral::query()->whereKey($clawbacks->modelKeys())->update(['clawed_back_item_id' => $item->id, 'updated_at' => now()]);
            $carried?->forceFill(['rolled_into_item_id' => $item->id])->save();

            $totals['lines']++;
            $totals[$line->payable ? 'payable' : 'carried']++;
            $totals['net_paise'] += $line->netPaise;
        }

        if ($totals['lines'] === 0) {
            throw ValidationException::withMessages(['periodEnd' => __('Nothing to pay or carry for this period: no verified bureau has earned commission in it.')]);
        }

        $this->audit->record('brokers.payout_run_created', $run, after: [
            'period' => $start->toDateString().'..'.$end->toDateString(),
        ] + $totals, reason: $reason, actor: $admin, subjectLabel: $run->code);

        return $run;
    }

    /**
     * Every bureau that may be paid now (R-A07-2), in code order.
     *
     * @return Collection<int, Broker>
     */
    private function payableBureaus(): Collection
    {
        return Broker::query()->where('status', BrokerStatus::Active)->where('kyc_status', BrokerKycStatus::Verified)
            ->whereNull('payouts_held_at')->orderBy('code')->lockForUpdate()->get(['id', 'code']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function period(string $start, string $end): array
    {
        $zone = (string) config('oppam.display_timezone');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $start, $zone) ?: null;
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $end, $zone) ?: null;

        if ($from === null || $from->format('Y-m-d') !== $start) {
            throw ValidationException::withMessages(['periodStart' => __('Choose the first day of the period.')]);
        }
        if ($to === null || $to->format('Y-m-d') !== $end) {
            throw ValidationException::withMessages(['periodEnd' => __('Choose the last day of the period.')]);
        }
        if (! $from->lt($to)) {
            throw ValidationException::withMessages(['periodEnd' => __('The period must end after it starts.')]);
        }
        if ($to->gte(CarbonImmutable::now($zone)->startOfDay())) {
            throw ValidationException::withMessages(['periodEnd' => __('A period can only be paid once it has ended (up to yesterday).')]);
        }

        return [$from, $to];
    }
}
