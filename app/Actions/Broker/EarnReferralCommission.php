<?php

declare(strict_types=1);

namespace App\Actions\Broker;

use App\Domain\Broker\ReferralCommission;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Enums\RefundStatus;
use App\Events\Broker\CommissionEarned;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\BrokerStaff;
use App\Models\Order;
use App\Models\Profile;
use App\Models\Refund;
use App\Notifications\Broker\CommissionEarned as CommissionEarnedNotification;
use Illuminate\Support\Facades\DB;

/**
 * Turn a profile's PENDING ledger row into EARNED (B.10 triggers) — one evaluator for every
 * trigger: an order was paid (OrderPaid), the profile was first approved (ApproveProfile), or an
 * admin linked a mistyped code (A07). It earns only when all of these hold:
 *
 * - the profile has a PENDING row (referral or managed — one commission per profile);
 * - the bureau is ACTIVE now (R-M13-8: an inactive bureau earns nothing new);
 * - the profile has reached ACTIVE (`published_at`) — paid before approval waits for the first
 *   approval (owner 2026-10-24);
 * - the profile's FIRST paid order exists; if that order was refunded the row goes VOID
 *   (R-M13-5) — a later order never earns instead.
 *
 * Idempotent (the row is locked and re-read); the Owner is told after commit (B.10 visibility:
 * Owner only — notification + live `commission.earned` on `broker-owner.{broker}`).
 */
final class EarnReferralCommission
{
    public function handle(string $profileId): ?BrokerReferral
    {
        $referral = DB::transaction(function () use ($profileId): ?BrokerReferral {
            $referral = BrokerReferral::query()->where('profile_id', $profileId)->lockForUpdate()->first();

            if ($referral === null || $referral->status !== ReferralStatus::Pending) {
                return null;
            }

            $published = Profile::withTrashed()->whereKey($profileId)->whereNotNull('published_at')->exists();
            $broker = Broker::query()->find($referral->broker_id);

            if (! $published || $broker === null || $broker->status !== BrokerStatus::Active) {
                return null;
            }

            $order = Order::query()->where('profile_id', $profileId)->where('status', OrderStatus::Paid)
                ->orderBy('paid_at')->orderBy('id')->first();

            if ($order === null) {
                return null;
            }

            if (Refund::query()->where('order_id', $order->id)->where('status', RefundStatus::Processed)->exists()) {
                $referral->forceFill([
                    'order_id' => $order->id,
                    'status' => ReferralStatus::Void,
                    'voided_at' => now(),
                    'void_reason' => ReferralVoidReason::Refund,
                ])->save();

                return null;
            }

            $base = ReferralCommission::base($order);

            $referral->forceFill([
                'order_id' => $order->id,
                'base_paise' => $base,
                'commission_bps' => $broker->commission_bps,
                'commission_paise' => ReferralCommission::amount($base, $broker->commission_bps),
                'status' => ReferralStatus::Earned,
                'earned_at' => now(),
            ])->save();

            return $referral;
        });

        if ($referral !== null) {
            $this->tellOwner($referral);
        }

        return $referral;
    }

    private function tellOwner(BrokerReferral $referral): void
    {
        $code = (string) Profile::withTrashed()->whereKey($referral->profile_id)->value('code');
        $amount = $referral->commission()->formatWithSymbol();

        CommissionEarned::dispatch($referral->broker_id, $code, $amount);

        BrokerStaff::query()->where('broker_id', $referral->broker_id)->where('role', BureauRole::Owner)
            ->where('status', BureauStaffStatus::Active)->with('user')->get()
            ->each(fn (BrokerStaff $owner) => $owner->user?->notify(new CommissionEarnedNotification($code, $amount)));
    }
}
