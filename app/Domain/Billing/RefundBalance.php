<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;

/** How much of a captured payment can still go back: its amount less every refund holding part of it. */
final class RefundBalance
{
    /** What has actually gone back: PROCESSED only, unlike left(), which also holds what is on its way. */
    public function returned(Payment $payment, ?Refund $except = null): int
    {
        return (int) Refund::query()->where('payment_id', $payment->id)->where('status', RefundStatus::Processed)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except?->id))
            ->sum('amount_paise');
    }

    public function left(Payment $payment, ?Refund $except = null): int
    {
        $held = (int) Refund::query()->where('payment_id', $payment->id)
            ->whereIn('status', RefundStatus::reserving())
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except?->id))
            ->sum('amount_paise');

        return max(0, $payment->amount_paise - $held);
    }
}
