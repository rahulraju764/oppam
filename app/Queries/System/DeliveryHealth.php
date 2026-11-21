<?php

declare(strict_types=1);

namespace App\Queries\System;

use App\Data\System\DeliveryChannelData;
use App\Enums\CommsChannel;
use App\Models\OutboundMessage;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A12 "outbound SMS / email delivery and bounces": the last 24 hours of the A14 delivery log
 * (outbound_messages), per channel — sent, delivered, failed, bounced — in ONE grouped query.
 * A message that failed and then bounced counts once, as a bounce (as LiveMetrics does).
 * Cached 60 s.
 */
final class DeliveryHealth
{
    public const CACHE_KEY = 'system:delivery-health';

    public const CACHE_SECONDS = 60;

    public function __construct(private readonly Cache $cache) {}

    /** @return list<DeliveryChannelData> email and SMS, in that order */
    public function current(): array
    {
        /** @var list<DeliveryChannelData> */
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->measure());
    }

    /** @return list<DeliveryChannelData> */
    private function measure(): array
    {
        $rows = OutboundMessage::query()
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('channel', [CommsChannel::Email->value, CommsChannel::Sms->value])
            ->groupBy('channel')
            ->selectRaw('channel, COUNT(*) AS sent, COUNT(delivered_at) AS delivered,
                SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
                SUM(CASE WHEN failed_at IS NOT NULL AND bounced_at IS NULL THEN 1 ELSE 0 END) AS failed')
            ->toBase()
            ->get()
            ->keyBy('channel');

        $channels = [];
        foreach ([CommsChannel::Email, CommsChannel::Sms] as $channel) {
            $row = $rows->get($channel->value);
            $channels[] = new DeliveryChannelData(
                channel: $channel,
                sent: (int) ($row->sent ?? 0),
                delivered: (int) ($row->delivered ?? 0),
                failed: (int) ($row->failed ?? 0),
                bounced: (int) ($row->bounced ?? 0),
            );
        }

        return $channels;
    }
}
