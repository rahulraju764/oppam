<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\PaymentStatus;

/**
 * One payment as the gateway reports it (a verified webhook, or a server-side look-up). `status`
 * is null for states that change nothing here (created, authorized, refunded …).
 */
final readonly class GatewayPaymentData
{
    public function __construct(
        public string $id,
        public string $orderId,
        public int $amountPaise,
        public string $currency,
        public ?PaymentStatus $status,
        public ?string $method = null,
        public ?string $errorCode = null,
    ) {}
}
