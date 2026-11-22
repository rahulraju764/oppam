<?php

declare(strict_types=1);

namespace App\Data\System;

use App\Enums\SettingKey;
use App\Support\Facades\Settings;

/** The A15 alert thresholds (`system.alert_*`), read once per check. */
final readonly class AlertThresholdsData
{
    public function __construct(
        public int $failedJobsPerHour,
        public int $signatureFailuresPerHour,
        public int $bouncePct,
        public int $bounceMinSent,
        public int $broadcastLagSeconds,
        public int $reverbDropPct,
        public int $clearMinutes,
    ) {}

    public static function fromSettings(): self
    {
        return new self(
            failedJobsPerHour: Settings::int(SettingKey::SystemAlertFailedJobsPerHour),
            signatureFailuresPerHour: Settings::int(SettingKey::SystemAlertSignatureFailuresPerHour),
            bouncePct: Settings::int(SettingKey::SystemAlertBouncePct),
            bounceMinSent: Settings::int(SettingKey::SystemAlertBounceMinSent),
            broadcastLagSeconds: Settings::int(SettingKey::SystemAlertBroadcastLagSeconds),
            reverbDropPct: Settings::int(SettingKey::SystemAlertReverbDropPct),
            clearMinutes: Settings::int(SettingKey::SystemAlertClearMinutes),
        );
    }
}
