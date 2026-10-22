<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Billing\GatewayPaymentData;
use App\Data\Billing\WebhookEventData;
use App\Enums\PaymentStatus;
use InvalidArgumentException;
use JsonException;

/**
 * Reads the fields we act on from a Razorpay webhook body (payment.captured, payment.failed,
 * order.paid) or a payment entity from the API. Called only AFTER the signature was verified.
 */
final class RazorpayWebhookPayload
{
    /** @throws InvalidArgumentException not a Razorpay event */
    public static function parse(string $body): WebhookEventData
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Webhook body is not JSON.', 0, $e);
        }

        if (! is_array($data) || ! is_string($data['event'] ?? null)) {
            throw new InvalidArgumentException('Webhook body has no event.');
        }

        $paymentEntity = $data['payload']['payment']['entity'] ?? null;
        $orderEntity = $data['payload']['order']['entity'] ?? null;
        $payment = is_array($paymentEntity) ? self::payment($paymentEntity) : null;
        $orderId = $payment !== null
            ? $payment->orderId
            : (is_array($orderEntity) && is_string($orderEntity['id'] ?? null) ? $orderEntity['id'] : null);

        return new WebhookEventData(event: $data['event'], gatewayOrderId: $orderId, payment: $payment);
    }

    /** Payer details Razorpay includes in a payment entity — never stored (no PII outside the member record). */
    private const PAYER_FIELDS = ['email', 'contact', 'vpa', 'card', 'bank_account', 'upi', 'customer_id', 'token_id'];

    /**
     * The body as it is kept in payment_webhook_events (A06 shows it): unchanged except that the
     * payment's payer details are replaced by "[redacted]". Call after the signature was verified.
     */
    public static function redacted(string $body): string
    {
        $data = json_decode($body, true);
        if (! is_array($data) || ! is_array($data['payload']['payment']['entity'] ?? null)) {
            return $body;
        }

        foreach (self::PAYER_FIELDS as $field) {
            if (array_key_exists($field, $data['payload']['payment']['entity']) && $data['payload']['payment']['entity'][$field] !== null) {
                $data['payload']['payment']['entity'][$field] = '[redacted]';
            }
        }

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param  array<mixed>  $entity */
    public static function payment(array $entity): ?GatewayPaymentData
    {
        $id = $entity['id'] ?? null;
        $orderId = $entity['order_id'] ?? null;
        if (! is_string($id) || ! is_string($orderId) || ! is_numeric($entity['amount'] ?? null)) {
            return null;
        }

        return new GatewayPaymentData(
            id: $id,
            orderId: $orderId,
            amountPaise: (int) $entity['amount'],
            currency: is_string($entity['currency'] ?? null) ? $entity['currency'] : '',
            status: match ($entity['status'] ?? null) {
                'captured' => PaymentStatus::Captured,
                'failed' => PaymentStatus::Failed,
                default => null,
            },
            method: is_string($entity['method'] ?? null) ? substr($entity['method'], 0, 30) : null,
            errorCode: is_string($entity['error_code'] ?? null) ? substr($entity['error_code'], 0, 60) : null,
        );
    }
}
