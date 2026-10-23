<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Billing\GatewayOrderData;
use App\Data\Billing\GatewayPaymentData;
use App\Data\Billing\GatewayRefundData;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use Carbon\CarbonInterface;

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

    /**
     * Every payment the gateway created in [$from, $to) — the daily reconciliation (A06).
     *
     * @return list<GatewayPaymentData>
     *
     * @throws PaymentGatewayUnavailable
     */
    public function paymentsBetween(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Refund part or all of a captured payment (A06). $receipt is our RFD… code (also put in the
     * notes, so the refund's webhook can be matched); the amount is in paise, GST included.
     *
     * @throws PaymentGatewayUnavailable the gateway refused or could not be reached — nothing was refunded
     */
    public function refund(string $paymentId, int $amountPaise, string $receipt): GatewayRefundData;
}
