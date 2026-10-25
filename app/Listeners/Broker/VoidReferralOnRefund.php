<?php

declare(strict_types=1);

namespace App\Listeners\Broker;

use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Enums\RefundStatus;
use App\Events\Billing\RefundProcessed;
use App\Models\BrokerReferral;
use App\Models\Refund;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * R-M13-5 / B.19: a processed refund of the commissioned order — full or partial (owner
 * 2026-10-24) — voids the ledger row. An EARNED row simply stops counting; a PAID row keeps its
 * payout_item_id, so the next payout run (P7.8) takes it back as a clawback. Idempotent.
 */
final class VoidReferralOnRefund implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(RefundProcessed $event): void
    {
        $orderId = Refund::query()->whereKey($event->refundId)->where('status', RefundStatus::Processed)->value('order_id');

        if (! is_string($orderId)) {
            return;
        }

        DB::transaction(function () use ($orderId): void {
            BrokerReferral::query()->where('order_id', $orderId)
                ->whereIn('status', [ReferralStatus::Earned, ReferralStatus::Paid])
                ->lockForUpdate()->get()
                ->each(fn (BrokerReferral $referral) => $referral->forceFill([
                    'status' => ReferralStatus::Void,
                    'voided_at' => now(),
                    'void_reason' => ReferralVoidReason::Refund,
                ])->save());
        });
    }
}
