<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Billing\GatewayOrderData;
use App\Data\Billing\GatewayPaymentData;
use App\Exceptions\Billing\PaymentGatewayUnavailable;

/**
 * The payment gateway (PRD §4: Razorpay orders + webhooks; hosted checkout, no card fields on our
 * pages). Bound by PAYMENT_DRIVER: `razorpay` (RazorpayGateway) or `fake` (FakePaymentGateway —
 * local, tests and Dusk; refused in production).
 */
interface PaymentGateway
{
    /** "razorpay" | "fake" — stored on each order. */
    public function name(): string;

    /** Keys present, so a checkout can be offered at all. */
    public function isConfigured(): bool;

    /** The fake gateway: payments are simulated, no money moves. */
    public function isTestMode(): bool;

    /** The public key the browser checkout opens with (never the secret). */
    public function publicKey(): string;

    /**
     * @param  array<string, string>  $notes  codes only (shown in the gateway dashboard)
     *
     * @throws PaymentGatewayUnavailable
     */
    public function createOrder(string $receipt, int $amountPaise, array $notes): GatewayOrderData;

    /** HMAC of the raw body with the webhook secret, compared in constant time. */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Every payment the gateway holds for one of its orders (reconciliation when a webhook is late).
     *
     * @return list<GatewayPaymentData>
     *
     * @throws PaymentGatewayUnavailable
     */
    public function paymentsForOrder(string $gatewayOrderId): array;
}
