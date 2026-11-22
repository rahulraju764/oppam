<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A12 alerts (PRD §11 A12: failed jobs > 10, missed schedules, webhook signature failures,
 * bounce > 5 %, broadcast lag > 5 s, Reverb connections drop > 30 %). system_alerts.kind.
 * `value` / `threshold` on the alert are in this kind's unit (see describe()).
 */
enum SystemAlertKind: string
{
    case FailedJobs = 'FAILED_JOBS';
    case MissedSchedule = 'MISSED_SCHEDULE';
    case WebhookSignatureFailures = 'WEBHOOK_SIGNATURE_FAILURES';
    case BounceRate = 'BOUNCE_RATE';
    case BroadcastLag = 'BROADCAST_LAG';
    case ReverbDrop = 'REVERB_DROP';

    public function label(): string
    {
        return match ($this) {
            self::FailedJobs => __('Failed jobs'),
            self::MissedSchedule => __('Missed scheduled tasks'),
            self::WebhookSignatureFailures => __('Webhook signature failures'),
            self::BounceRate => __('Email / SMS bounce rate'),
            self::BroadcastLag => __('Broadcast queue lag'),
            self::ReverbDrop => __('Live connections dropped'),
        };
    }

    /** danger = members are affected now; warning = something to look at today. */
    public function level(): string
    {
        return match ($this) {
            self::BroadcastLag, self::ReverbDrop, self::FailedJobs => 'danger',
            self::MissedSchedule, self::WebhookSignatureFailures, self::BounceRate => 'warning',
        };
    }

    /** One line for the A02 strip, the health panel and the email — numbers only, never member data. */
    public function describe(int $value, int $threshold): string
    {
        return match ($this) {
            self::FailedJobs => __(':value jobs failed in the last hour (alert above :threshold).', ['value' => number_format($value), 'threshold' => number_format($threshold)]),
            self::MissedSchedule => trans_choice('{1} 1 scheduled task missed its last run.|[2,*] :value scheduled tasks missed their last run.', $value, ['value' => $value]),
            self::WebhookSignatureFailures => __(':value webhook signature failures in the last hour (alert at :threshold).', ['value' => number_format($value), 'threshold' => number_format($threshold)]),
            self::BounceRate => __(':value % of email or SMS bounced in 24 hours (alert above :threshold %).', ['value' => $value, 'threshold' => $threshold]),
            self::BroadcastLag => __('Live updates wait :value s on the broadcasts queue (alert above :threshold s).', ['value' => number_format($value / 1000, 1), 'threshold' => number_format($threshold / 1000)]),
            self::ReverbDrop => __('Live connections fell by :value % (alert above :threshold %).', ['value' => $value, 'threshold' => $threshold]),
        };
    }
}
