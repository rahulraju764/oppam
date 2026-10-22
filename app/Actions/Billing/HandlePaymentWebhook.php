<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Exceptions\Billing\InvalidWebhook;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\RazorpayWebhookPayload;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A payment webhook (F04 "Webhook payment.captured (signature verified, idempotent)"):
 *  1. the signature over the RAW body must verify, else nothing is written (InvalidWebhook → 400);
 *  2. the event is stored once per gateway event id (payer contact details redacted) — a
 *     redelivery of a processed event is answered "replayed" without acting again;
 *  3. payment.captured / order.paid → ActivateSubscription (itself idempotent on the payment id);
 *     payment.failed → RecordFailedPayment; anything else is stored and ignored.
 */
final class HandlePaymentWebhook
{
    public const REPLAYED = 'replayed';

    public const IGNORED = 'ignored';

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ActivateSubscription $activate,
        private readonly RecordFailedPayment $failed,
    ) {}

    /** @throws InvalidWebhook */
    public function handle(string $body, string $signature, ?string $eventId): string
    {
        if (! $this->gateway->verifyWebhookSignature($body, $signature)) {
            throw InvalidWebhook::signature();
        }

        try {
            $data = RazorpayWebhookPayload::parse($body);
        } catch (InvalidArgumentException) {
            throw InvalidWebhook::body();
        }

        // Razorpay sends X-Razorpay-Event-Id; without one, the body itself identifies the delivery.
        $eventId = $eventId !== null && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $eventId) === 1
            ? $eventId
            : 'body_'.substr(hash('sha256', $body), 0, 48);

        PaymentWebhookEvent::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'gateway' => $this->gateway->name(),
            'event_id' => $eventId,
            'event' => substr($data->event, 0, 60),
            'payload' => RazorpayWebhookPayload::redacted($body),   // payer email / phone / UPI id never stored
            'gateway_order_id' => $data->gatewayOrderId === null ? null : substr($data->gatewayOrderId, 0, 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = PaymentWebhookEvent::query()->where('gateway', $this->gateway->name())->where('event_id', $eventId)->firstOrFail();
        if ($stored->processed_at !== null) {
            return self::REPLAYED;
        }

        $payment = $data->payment;
        $outcome = match (true) {
            $payment === null => self::IGNORED,
            in_array($data->event, ['payment.captured', 'order.paid'], true) && $payment->status === PaymentStatus::Captured => $this->activate->handle($payment),
            $data->event === 'payment.failed' && $payment->status === PaymentStatus::Failed => $this->failed->handle($payment),
            default => self::IGNORED,
        };

        $stored->forceFill(['processed_at' => now(), 'outcome' => $outcome])->save();

        return $outcome;
    }
}
