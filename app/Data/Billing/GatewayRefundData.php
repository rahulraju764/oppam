<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\RefundStatus;

/**
 * A refund as the payment gateway reports it (create response or `refund.*` webhook). `status`
 * is Processing (accepted, money on its way), Processed or Failed; `refundCode` is our RFD… code
 * sent in the refund's notes, so a webhook finds its row even before the create call returned.
 */
final readonly class GatewayRefundData
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public int $amountPaise,
        public RefundStatus $status,
        public ?string $refundCode = null,
    ) {}
}
