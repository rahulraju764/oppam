<?php

declare(strict_types=1);

namespace App\Data\System;

/** The Webhooks card of the A12 health panel: per provider (24 h) and the payment events not processed. */
final readonly class WebhookHealthData
{
    /**
     * @param  list<WebhookProviderHealthData>  $providers
     * @param  list<UnprocessedWebhookData>  $unprocessed  newest first, at most WebhookHealth::UNPROCESSED_SHOWN
     */
    public function __construct(
        public array $providers,
        public array $unprocessed,
        public int $unprocessedCount,
    ) {}
}
