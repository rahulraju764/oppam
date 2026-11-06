<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Data\Billing\GatewayOrderData;
use App\Data\Billing\GatewayPaymentData;
use App\Data\Billing\GatewayRefundData;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * A stand-in for Razorpay (PAYMENT_DRIVER=fake — local development, tests and Dusk; refused in
 * production). Orders and payments live in the cache for a day. capture() / fail() build a webhook
 * exactly as Razorpay would send it, signed with this gateway's secret, so a simulated payment goes
 * through the same signature check, idempotency and activation as a real one.
 */
final class FakePaymentGateway implements PaymentGateway
{
    private const KEY = 'fake-gateway:';

    private const TTL_SECONDS = 86_400;

    private const REFUND_TTL_SECONDS = 2_592_000;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isTestMode(): bool
    {
        return true;
    }

    public function publicKey(): string
    {
        return 'rzp_test_fake';
    }

    public function createOrder(string $receipt, int $amountPaise, array $notes): GatewayOrderData
    {
        $id = 'order_'.Str::random(14);
        $this->cache->put(self::KEY.'order:'.$id, ['amount' => $amountPaise, 'receipt' => $receipt], self::TTL_SECONDS);

        return new GatewayOrderData($id, $amountPaise, 'INR');
    }

    /** The amount the fake gateway was asked to charge for its order (tests: "the server set it"). */
    public function orderAmount(string $gatewayOrderId): ?int
    {
        $order = $this->cache->get(self::KEY.'order:'.$gatewayOrderId);

        return is_array($order) ? (int) $order['amount'] : null;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $signature !== '' && hash_equals($this->sign($payload), $signature);
    }

    public function paymentsForOrder(string $gatewayOrderId): array
    {
        $payments = [];
        foreach ((array) $this->cache->get(self::KEY.'payments:'.$gatewayOrderId, []) as $entity) {
            $payment = is_array($entity) ? RazorpayWebhookPayload::payment($entity) : null;
            if ($payment instanceof GatewayPaymentData) {
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    public function paymentsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $payments = [];
        foreach ((array) $this->cache->get(self::KEY.'ledger', []) as $entity) {
            $at = is_array($entity) ? (int) ($entity['created_at'] ?? 0) : 0;
            $payment = is_array($entity) && $at >= $from->getTimestamp() && $at < $to->getTimestamp() ? RazorpayWebhookPayload::payment($entity) : null;
            if ($payment instanceof GatewayPaymentData) {
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    public function refund(string $paymentId, int $amountPaise, string $receipt): GatewayRefundData
    {
        $mode = $this->cache->pull(self::KEY.'next-refund');
        if ($mode === 'unavailable') {
            throw PaymentGatewayUnavailable::because('fake gateway set to refuse the refund');
        }

        $data = $this->plantRefund($paymentId, $amountPaise, $receipt, $mode === 'processed' || $mode === null ? 'processed' : 'pending');
        if ($mode === 'sent-unanswered') {
            // The gateway took it, but the answer never arrived (a timeout): the caller can't know it went.
            throw PaymentGatewayUnavailable::because('fake gateway: refund created, answer lost');
        }

        return $data;
    }

    public function refundsForPayment(string $paymentId): array
    {
        if ($this->cache->pull(self::KEY.'next-lookup') === 'unavailable') {
            throw PaymentGatewayUnavailable::because('fake gateway set to refuse the lookup');
        }

        $refunds = [];
        foreach ((array) $this->cache->get(self::KEY.'payment-refunds:'.$paymentId, []) as $refundId) {
            $entity = $this->cache->get(self::KEY.'refund:'.$refundId);
            $refund = is_array($entity) ? RazorpayWebhookPayload::refund($entity) : null;
            if ($refund instanceof GatewayRefundData) {
                $refunds[] = $refund;
            }
        }

        return $refunds;
    }

    /**
     * Tests / local: how the next refund call behaves — 'processed' (the default), 'pending' (accepted,
     * not yet processed), 'unavailable' (refused, nothing sent) or 'sent-unanswered' (sent, but the
     * answer was lost: the call fails although the refund exists at the gateway).
     */
    public function nextRefund(string $mode): void
    {
        $this->cache->put(self::KEY.'next-refund', $mode, self::TTL_SECONDS);
    }

    /**
     * Tests: what the gateway's own records say about a refund, with no webhook sent (one that was lost).
     *
     * @param  'pending'|'processed'|'failed'  $status
     */
    public function refundStatusAtGateway(string $refundId, string $status): void
    {
        $refund = $this->cache->get(self::KEY.'refund:'.$refundId);
        if (is_array($refund)) {
            $this->cache->put(self::KEY.'refund:'.$refundId, [...$refund, 'status' => $status], self::REFUND_TTL_SECONDS);
        }
    }

    /** Tests: the next refundsForPayment() can't reach the gateway. */
    public function failNextRefundLookup(): void
    {
        $this->cache->put(self::KEY.'next-lookup', 'unavailable', self::TTL_SECONDS);
    }

    /**
     * A refund as the gateway holds it — one of ours ($receipt = its RFD… code), or one made by hand in
     * the dashboard ($receipt null). Kept a month: the stuck-refund rules wait 48 hours.
     *
     * @param  'pending'|'processed'|'failed'  $status
     */
    public function plantRefund(string $paymentId, int $amountPaise, ?string $receipt, string $status = 'processed'): GatewayRefundData
    {
        $entity = [
            'id' => 'rfnd_'.Str::random(14),
            'entity' => 'refund',
            'payment_id' => $paymentId,
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => $receipt === null ? [] : ['refund_code' => $receipt],
            'status' => $status,
        ];
        $this->cache->put(self::KEY.'refund:'.$entity['id'], $entity, self::REFUND_TTL_SECONDS);
        $index = self::KEY.'payment-refunds:'.$paymentId;
        $this->cache->put($index, [...(array) $this->cache->get($index, []), $entity['id']], self::REFUND_TTL_SECONDS);

        return RazorpayWebhookPayload::refund($entity) ?? throw PaymentGatewayUnavailable::because('fake refund');
    }

    /**
     * A signed `refund.processed` / `refund.failed` webhook for a refund this gateway created.
     *
     * @return array{body: string, signature: string, event_id: string}
     */
    public function refundWebhook(string $refundId, string $event = 'refund.processed'): array
    {
        $stored = $this->cache->get(self::KEY.'refund:'.$refundId);
        $refund = is_array($stored) ? $stored : [];
        $refund['status'] = match ($event) {
            'refund.failed' => 'failed',
            'refund.created' => 'pending',
            default => 'processed',
        };
        if (is_array($stored)) {
            $this->cache->put(self::KEY.'refund:'.$refundId, $refund, self::REFUND_TTL_SECONDS);   // a later lookup sees what was announced
        }

        $body = (string) json_encode([
            'entity' => 'event',
            'account_id' => 'acc_fake',
            'event' => $event,
            'contains' => ['refund', 'payment'],
            'payload' => ['refund' => ['entity' => $refund]],
            'created_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return ['body' => $body, 'signature' => $this->sign($body), 'event_id' => 'evt_'.Str::random(14)];
    }

    /**
     * A captured payment for the order (by default its full amount) and the webhook announcing it.
     *
     * @return array{body: string, signature: string, event_id: string, payment_id: string}
     */
    public function capture(string $gatewayOrderId, ?int $amountPaise = null, string $event = 'payment.captured'): array
    {
        return $this->pay($gatewayOrderId, $amountPaise ?? $this->orderAmount($gatewayOrderId) ?? 0, 'captured', $event);
    }

    /** @return array{body: string, signature: string, event_id: string, payment_id: string} */
    public function fail(string $gatewayOrderId): array
    {
        return $this->pay($gatewayOrderId, $this->orderAmount($gatewayOrderId) ?? 0, 'failed', 'payment.failed');
    }

    /**
     * A signed webhook for any payment entity (tests: replays, order.paid after payment.captured).
     *
     * @param  array<string, mixed>  $payment
     * @return array{body: string, signature: string, event_id: string}
     */
    public function webhook(string $event, array $payment): array
    {
        $body = (string) json_encode([
            'entity' => 'event',
            'account_id' => 'acc_fake',
            'event' => $event,
            'contains' => ['payment'],
            'payload' => ['payment' => ['entity' => $payment]],
            'created_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return ['body' => $body, 'signature' => $this->sign($body), 'event_id' => 'evt_'.Str::random(14)];
    }

    /** @return array{body: string, signature: string, event_id: string, payment_id: string} */
    private function pay(string $gatewayOrderId, int $amountPaise, string $status, string $event): array
    {
        $payment = [
            'id' => 'pay_'.Str::random(14),
            'entity' => 'payment',
            'amount' => $amountPaise,
            'currency' => 'INR',
            'status' => $status,
            'order_id' => $gatewayOrderId,
            'method' => 'upi',
            'error_code' => $status === 'failed' ? 'BAD_REQUEST_ERROR' : null,
            'created_at' => now()->getTimestamp(),
        ];

        $key = self::KEY.'payments:'.$gatewayOrderId;
        $this->cache->put($key, [...(array) $this->cache->get($key, []), $payment], self::TTL_SECONDS);
        // Every payment, for paymentsBetween() (the daily reconciliation).
        $this->cache->put(self::KEY.'ledger', [...(array) $this->cache->get(self::KEY.'ledger', []), $payment], self::TTL_SECONDS);

        return [...$this->webhook($event, $payment), 'payment_id' => $payment['id']];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->webhookSecret);
    }
}
