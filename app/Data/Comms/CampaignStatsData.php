<?php

declare(strict_types=1);

namespace App\Data\Comms;

/**
 * A14 per-campaign numbers (P8.3b1). audience = members frozen at submit; pending = not reached
 * yet; sent = handed to the channel; skipped = no longer eligible at send (or cancelled first);
 * failed = the channel refused it (our side or the provider's); delivered = the provider confirmed
 * it (in-app: written to the bell = delivered); bounced; opened (email, provider events only);
 * clicked (the button); unsubscribed (email one-click).
 */
final readonly class CampaignStatsData
{
    public function __construct(
        public int $audience = 0,
        public int $pending = 0,
        public int $sent = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public int $delivered = 0,
        public int $bounced = 0,
        public int $opened = 0,
        public int $clicked = 0,
        public int $unsubscribed = 0,
    ) {}
}
