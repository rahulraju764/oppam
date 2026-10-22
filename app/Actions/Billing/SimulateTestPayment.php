<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentGateway;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Payments\FakePaymentGateway;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Complete test payment" on checkout with PAYMENT_DRIVER=fake (local development and Dusk). The
 * fake gateway records a payment and signs the webhook Razorpay would send; it then goes through
 * HandlePaymentWebhook like a real one — signature, idempotency, activation, broadcasts. Not
 * available with a real gateway or in production (404); owner-scoped (another member's order: 404).
 */
final class SimulateTestPayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly HandlePaymentWebhook $webhook,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws NotFoundHttpException
     * @throws ImpersonationRestricted
     */
    public function handle(User $member, Order $order, bool $succeed = true): string
    {
        $this->impersonation->assertAllowed();

        if (! $this->gateway instanceof FakePaymentGateway || app()->isProduction()
            || $member->profile === null || $order->profile_id !== $member->profile->id || $order->gateway_order_id === null) {
            throw new NotFoundHttpException;
        }

        $delivery = $succeed ? $this->gateway->capture($order->gateway_order_id) : $this->gateway->fail($order->gateway_order_id);

        return $this->webhook->handle($delivery['body'], $delivery['signature'], $delivery['event_id']);
    }
}
