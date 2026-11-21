<?php

declare(strict_types=1);

namespace App\Data\System;

use App\Enums\CommsChannel;

/**
 * One outbound channel's last 24 hours on the A12 health panel (outbound_messages). A message that
 * failed and then bounced counts once, as a bounce. `bounceRatePct` = bounced / sent (null when
 * nothing was sent).
 */
final readonly class DeliveryChannelData
{
    public function __construct(
        public CommsChannel $channel,
        public int $sent,
        public int $delivered,
        public int $failed,
        public int $bounced,
    ) {}

    public function bounceRatePct(): ?float
    {
        return $this->sent === 0 ? null : round($this->bounced * 100 / $this->sent, 1);
    }

    public function failureRatePct(): ?float
    {
        return $this->sent === 0 ? null : round(($this->failed + $this->bounced) * 100 / $this->sent, 1);
    }
}
