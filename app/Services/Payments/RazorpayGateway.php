<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Data\Billing\GatewayOrderData;
use App\Data\Billing\GatewayPaymentData;
use App\Data\Billing\GatewayRefundData;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use Carbon\CarbonInterface;
use Razorpay\Api\Api;
use Razorpay\Api\Order as RazorpayOrder;
use Razorpay\Api\Payment as RazorpayPayment;
use Throwable;

/**
 * Razorpay Orders API + webhook signatures (PRD §4, M10). Live or test keys by the
 * `payments.razorpay_live` flag (AppServiceProvider). The key secret and webhook secret never
 * leave the server; the browser gets only the key id.
 */
final class RazorpayGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '' && $this->webhookSecret !== '';
    }

    public function isTestMode(): bool
    {
        return false;
    }

    public function publicKey(): string
    {
        return $this->keyId;
    }

    public function createOrder(string $receipt, int $amountPaise, array $notes): GatewayOrderData
    {
        try {
            $order = $this->orders()->create([
                'receipt' => $receipt,
                'amount' => $amountPaise,
                'currency' => 'INR',
                'notes' => $notes,
            ])->toArray();
        } catch (Throwable $e) {
            throw PaymentGatewayUnavailable::because('order create failed', $e);
        }

        if (! is_string($order['id'] ?? null) || (int) ($order['amount'] ?? 0) !== $amountPaise) {
            throw PaymentGatewayUnavailable::because('unexpected order response');
        }

        return new GatewayOrderData($order['id'], $amountPaise, (string) ($order['currency'] ?? 'INR'));
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->webhookSecret !== ''
            && $signature !== ''
            && hash_equals(hash_hmac('sha256', $payload, $this->webhookSecret), $signature);
    }

    public function paymentsForOrder(string $gatewayOrderId): array
    {
        try {
            $items = $this->orders()->fetch($gatewayOrderId)->payments()->toArray()['items'] ?? [];
        } catch (Throwable $e) {
            throw PaymentGatewayUnavailable::because('payments fetch failed', $e);
        }

        $payments = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $payment = is_array($item) ? RazorpayWebhookPayload::payment($item) : null;
            if ($payment instanceof GatewayPaymentData) {
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    public function paymentsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $payments = [];
        $skip = 0;

        do {
            try {
                new Api($this->keyId, $this->keySecret);
                $items = (new RazorpayPayment)->all(['from' => $from->getTimestamp(), 'to' => $to->getTimestamp() - 1, 'count' => 100, 'skip' => $skip])->toArray()['items'] ?? [];
            } catch (Throwable $e) {
                throw PaymentGatewayUnavailable::because('payments list failed', $e);
            }

            $items = is_array($items) ? $items : [];
            foreach ($items as $item) {
                $payment = is_array($item) ? RazorpayWebhookPayload::payment($item) : null;
                if ($payment instanceof GatewayPaymentData) {
                    $payments[] = $payment;
                }
            }
            $skip += 100;
        } while (count($items) === 100 && $skip < 10_000);   // a day's payments; the cap keeps a bad loop finite

        return $payments;
    }

    public function refund(string $paymentId, int $amountPaise, string $receipt): GatewayRefundData
    {
        try {
            new Api($this->keyId, $this->keySecret);
            $refund = (new RazorpayPayment)->fetch($paymentId)->refund([
                'amount' => $amountPaise,
                'speed' => 'normal',
                'receipt' => $receipt,
                'notes' => ['refund_code' => $receipt],
            ])->toArray();
        } catch (Throwable $e) {
            throw PaymentGatewayUnavailable::because('refund create failed', $e);
        }

        $data = RazorpayWebhookPayload::refund($refund);
        if ($data === null || $data->amountPaise !== $amountPaise || $data->paymentId !== $paymentId) {
            throw PaymentGatewayUnavailable::because('unexpected refund response');
        }

        return $data;
    }

    /**
     * The SDK's Orders resource. `new Api(…)` stores the credentials on the SDK (static), which is
     * what its own `$api->order` magic getter relies on; this is the same, minus the magic.
     */
    private function orders(): RazorpayOrder
    {
        new Api($this->keyId, $this->keySecret);

        return new RazorpayOrder;
    }
}
