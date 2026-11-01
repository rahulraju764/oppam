<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;

/** How much of a captured payment can still go back: its amount less every refund holding part of it. */
final class RefundBalance
{
    public function left(Payment $payment): int
    {
        $held = (int) Refund::query()->where('payment_id', $payment->id)
            ->whereIn('status', RefundStatus::reserving())->sum('amount_paise');

        return max(0, $payment->amount_paise - $held);
    }
}
