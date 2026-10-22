<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The success page is still waiting for the webhook: ask the gateway directly (server to server)
 * for the order's payments and act on them exactly as a webhook would — never on what the browser
 * says. Owner-scoped (another member's order: 404); at most once per order every 10 seconds.
 * Also what makes local development work, where no webhook can reach the machine.
 */
final class ReconcileOrder
{
    private const EVERY_SECONDS = 10;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ActivateSubscription $activate,
        private readonly RecordFailedPayment $failed,
        private readonly Cache $cache,
    ) {}

    /** @throws NotFoundHttpException */
    public function handle(User $member, Order $order): Order
    {
        if ($member->profile === null || $order->profile_id !== $member->profile->id) {
            throw new NotFoundHttpException;
        }

        if (! $order->status->isPayable() || $order->gateway_order_id === null || $order->gateway !== $this->gateway->name()
            || ! $this->cache->add('billing-reconcile:'.$order->id, 1, self::EVERY_SECONDS)) {
            return $order;
        }

        try {
            $payments = $this->gateway->paymentsForOrder($order->gateway_order_id);
        } catch (PaymentGatewayUnavailable $e) {
            report($e);

            return $order;
        }

        foreach ($payments as $payment) {
            if ($payment->orderId !== $order->gateway_order_id) {
                continue;
            }

            match ($payment->status) {
                PaymentStatus::Captured => $this->activate->handle($payment),
                PaymentStatus::Failed => $this->failed->handle($payment),
                null => null,
            };
        }

        return $order->refresh();
    }
}
