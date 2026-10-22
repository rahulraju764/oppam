<?php

declare(strict_types=1);

namespace App\Data\Billing;

/** An order created at the payment gateway for one of our orders (PaymentGateway::createOrder). */
final readonly class GatewayOrderData
{
    public function __construct(
        public string $id,
        public int $amountPaise,
        public string $currency,
    ) {}
}
