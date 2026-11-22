<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * What CheckSystemHealth read for the A12 alert rules. A null field = that source couldn't be read
 * this minute: its alert is neither opened nor closed (AlertRules reports it as unknown).
 */
final readonly class HealthReadingsData
{
    /** @param  list<DeliveryChannelData>|null  $delivery */
    public function __construct(
        public ?int $failedJobsLastHour,
        public ?int $missedSchedules,
        public ?int $signatureFailuresLastHour,
        public ?array $delivery,
        public ?BroadcastLagData $broadcastLag,
        public ?ReverbTrendData $reverb,
    ) {}
}
