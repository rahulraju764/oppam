<?php

declare(strict_types=1);

namespace App\Data\Comms;

use App\Enums\CommsChannel;
use Carbon\CarbonImmutable;

/** What the A14 campaign builder saves (P8.3b1); validated by SaveCampaign. */
final readonly class CampaignData
{
    public function __construct(
        public string $name,
        public CommsChannel $channel,
        public CampaignSegment $segment,
        public ?string $subject,
        public string $message,
        public ?string $actionUrl = null,
        public ?string $actionText = null,
        public ?string $dltTemplateId = null,
        public ?CarbonImmutable $scheduledAt = null,
        public int $throttlePerMinute = 300,
        public ?string $templateId = null,
    ) {}
}
