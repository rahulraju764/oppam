<?php

declare(strict_types=1);

namespace App\Data\System;

use Carbon\CarbonImmutable;

/**
 * A stored, signature-valid payment webhook whose processing didn't finish (payment_webhook_events
 * with processed_at null). Identified by the gateway's own event id — never the row's ULID.
 */
final readonly class UnprocessedWebhookData
{
    public function __construct(
        public string $gateway,
        public string $eventId,
        public string $event,
        public ?string $gatewayOrderId,
        public CarbonImmutable $receivedAt,
    ) {}
}
