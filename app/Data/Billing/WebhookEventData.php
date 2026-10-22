<?php

declare(strict_types=1);

namespace App\Data\Billing;

/** A parsed, signature-verified payment webhook (only the fields we act on). */
final readonly class WebhookEventData
{
    public function __construct(
        public string $event,
        public ?string $gatewayOrderId,
        public ?GatewayPaymentData $payment,
    ) {}
}
