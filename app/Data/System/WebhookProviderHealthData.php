<?php

declare(strict_types=1);

namespace App\Data\System;

use App\Enums\WebhookProvider;
use Carbon\CarbonImmutable;

/** One webhook provider's last 24 hours on the A12 health panel (from webhook_receipts). */
final readonly class WebhookProviderHealthData
{
    public function __construct(
        public WebhookProvider $provider,
        public int $accepted,
        public int $rejectedSignature,
        public int $rejectedBody,
        public int $errors,
        public ?CarbonImmutable $lastReceivedAt,
    ) {}
}
