<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Billing\GatewayPaymentData;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * A payment attempt the gateway refused (`payment.failed`). The order stays open — Razorpay lets
 * the member try again on the same order — and the success page shows "didn't go through" while
 * the latest attempt is a failure. Never overwrites a captured payment.
 */
final class RecordFailedPayment
{
    public const RECORDED = 'payment_failed';

    public function handle(GatewayPaymentData $payment): string
    {
        return DB::transaction(function () use ($payment): string {
            $order = Order::query()->where('gateway_order_id', $payment->orderId)->lockForUpdate()->first();
            if ($order === null) {
                return ActivateSubscription::UNKNOWN_ORDER;
            }

            $row = Payment::query()->where('gateway_payment_id', $payment->id)->lockForUpdate()->first();
            if ($row?->status === PaymentStatus::Captured) {
                return ActivateSubscription::DUPLICATE;
            }

            ($row ?? new Payment)->forceFill([
                'order_id' => $order->id,
                'gateway_payment_id' => $payment->id,
                'amount_paise' => $payment->amountPaise,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'status' => PaymentStatus::Failed,
                'error_code' => $payment->errorCode,
            ])->save();

            return self::RECORDED;
        });
    }
}
