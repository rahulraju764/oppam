<?php

declare(strict_types=1);

namespace App\Queries\System;

use App\Data\System\UnprocessedWebhookData;
use App\Data\System\WebhookHealthData;
use App\Data\System\WebhookProviderHealthData;
use App\Enums\WebhookOutcome;
use App\Enums\WebhookProvider;
use App\Models\PaymentWebhookEvent;
use App\Models\WebhookReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A12 "webhook status": per provider, the last 24 hours of webhook_receipts by outcome (ONE grouped
 * query) and the latest delivery; and the stored payment events whose processing didn't finish
 * (payment_webhook_events.processed_at IS NULL, older than a minute so one still being handled
 * isn't listed). Replay is P9.2b2. Cached 30 s.
 */
final class WebhookHealth
{
    public const CACHE_KEY = 'system:webhook-health';

    public const CACHE_SECONDS = 30;

    public const UNPROCESSED_SHOWN = 20;

    /** A stored event younger than this may still be in its own request. */
    public const IN_FLIGHT_SECONDS = 60;

    public function __construct(private readonly Cache $cache) {}

    public function current(): WebhookHealthData
    {
        /** @var WebhookHealthData */
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): WebhookHealthData => $this->measure());
    }

    private function measure(): WebhookHealthData
    {
        $rows = WebhookReceipt::query()
            ->where('received_at', '>=', now()->subDay())
            ->groupBy('provider', 'outcome')
            ->selectRaw('provider, outcome, COUNT(*) AS total, MAX(received_at) AS latest_at')
            ->toBase()
            ->get();

        $counts = [];
        $latest = [];
        foreach ($rows as $row) {
            $provider = (string) $row->provider;
            $counts[$provider][(string) $row->outcome] = (int) $row->total;
            $at = CarbonImmutable::parse((string) $row->latest_at);
            $latest[$provider] = isset($latest[$provider]) && $latest[$provider]->gt($at) ? $latest[$provider] : $at;
        }

        $providers = [];
        foreach (WebhookProvider::cases() as $provider) {
            $of = $counts[$provider->value] ?? [];
            $providers[] = new WebhookProviderHealthData(
                provider: $provider,
                accepted: $of[WebhookOutcome::Accepted->value] ?? 0,
                rejectedSignature: $of[WebhookOutcome::RejectedSignature->value] ?? 0,
                rejectedBody: $of[WebhookOutcome::RejectedBody->value] ?? 0,
                errors: $of[WebhookOutcome::Error->value] ?? 0,
                lastReceivedAt: $latest[$provider->value] ?? null,
            );
        }

        $pending = PaymentWebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<=', now()->subSeconds(self::IN_FLIGHT_SECONDS));

        $unprocessed = (clone $pending)
            ->orderByDesc('created_at')
            ->limit(self::UNPROCESSED_SHOWN)
            ->get(['gateway', 'event_id', 'event', 'gateway_order_id', 'created_at'])
            ->map(fn (PaymentWebhookEvent $event): UnprocessedWebhookData => new UnprocessedWebhookData(
                gateway: $event->gateway,
                eventId: $event->event_id,
                event: $event->event,
                gatewayOrderId: $event->gateway_order_id,
                receivedAt: CarbonImmutable::parse((string) $event->getAttribute('created_at')),
            ))
            ->all();

        return new WebhookHealthData(
            providers: $providers,
            unprocessed: array_values($unprocessed),
            unprocessedCount: $pending->count(),
        );
    }
}
