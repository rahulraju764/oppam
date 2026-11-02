<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Actions\Billing\ActivateSubscription;
use App\Contracts\PaymentGateway;
use App\Enums\InvestigationType;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\BillingInvestigation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A06 "daily reconciliation job with investigate rows", 02:00 IST over the previous two IST days
 * (a late capture isn't missed). Each captured gateway payment goes through ActivateSubscription,
 * which activates one we never heard about and is idempotent otherwise; whatever that can't settle
 * becomes a row. Captured here but absent at the gateway → a row; a refund still PROCESSING after
 * 48 h → a row. Rows are fingerprinted, so a re-run adds nothing. If the gateway is down the job
 * fails and retries; nothing is written from a partial list.
 */
final class ReconcilePayments implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 900;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PaymentGateway $gateway, ActivateSubscription $activate): void
    {
        $today = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfDay();
        $from = $today->subDays(2)->utc();
        $to = $today->utc();

        $seen = [];
        // A day earlier than the local window: a payment we recorded late was created earlier at the gateway.
        foreach ($gateway->paymentsBetween($from->subDay(), $to) as $payment) {
            $seen[$payment->id] = true;
            if ($payment->status !== PaymentStatus::Captured) {
                continue;
            }

            $outcome = $activate->handle($payment);
            $type = match ($outcome) {
                ActivateSubscription::UNKNOWN_ORDER => InvestigationType::UnknownOrder,
                ActivateSubscription::AMOUNT_MISMATCH => InvestigationType::AmountMismatch,
                default => null,
            };
            $extra = $outcome === ActivateSubscription::EXTRA_PAYMENT || ($outcome === ActivateSubscription::DUPLICATE && $this->isExtra($payment->id));
            $type ??= $extra ? InvestigationType::ExtraPayment : null;

            if ($type !== null) {
                $order = Order::query()->where('gateway_order_id', $payment->orderId)->first();
                BillingInvestigation::raise($type, $payment->id, ['amount_paise' => $payment->amountPaise, 'gateway_order' => $payment->orderId, 'order' => $order?->code],
                    $order?->id, null, $payment->id);
            }
        }

        Payment::query()->where('status', PaymentStatus::Captured)->whereBetween('captured_at', [$from, $to])
            ->with('order:id,code,gateway')
            ->lazyById(500)
            ->each(function (Payment $payment) use ($seen, $gateway): void {
                if (! isset($seen[$payment->gateway_payment_id]) && $payment->order?->gateway === $gateway->name()) {
                    BillingInvestigation::raise(InvestigationType::NotAtGateway, $payment->gateway_payment_id, ['amount_paise' => $payment->amount_paise, 'order' => $payment->order->code],
                        $payment->order_id, null, $payment->gateway_payment_id);
                }
            });

        Refund::query()->where('status', RefundStatus::Processing)->where('decided_at', '<=', now()->subHours(Refund::STUCK_HOURS))
            ->lazyById(500)
            ->each(fn (Refund $refund) => BillingInvestigation::raise(InvestigationType::RefundStuck, $refund->code, ['refund' => $refund->code, 'amount_paise' => $refund->amount_paise], $refund->order_id, $refund->id));
    }

    /** A captured payment recorded earlier on an order another payment paid. */
    private function isExtra(string $gatewayPaymentId): bool
    {
        $payment = Payment::query()->where('gateway_payment_id', $gatewayPaymentId)->with('order:id,paid_payment_id')->first();

        return $payment !== null && $payment->order?->paid_payment_id !== null && $payment->order->paid_payment_id !== $payment->id;
    }
}
