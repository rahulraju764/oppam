<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Data\System\AlertEvaluationData;
use App\Data\System\AlertThresholdsData;
use App\Data\System\HealthReadingsData;
use App\Enums\SystemAlertKind;
use App\Queries\System\RealtimeHealth;

/**
 * A12 alert rules (PRD §11 A12), pure: readings + A15 thresholds → which alerts are crossed.
 *  - failed jobs in the last hour > threshold (PRD "> 10");
 *  - any scheduled task missed its last run;
 *  - webhook signature failures in the last hour ≥ threshold;
 *  - email or SMS bounce rate (24 h) > threshold, for a channel that sent at least the minimum;
 *  - broadcast queue lag > threshold seconds;
 *  - live connections fell by more than threshold % against the highest of the last minutes —
 *    only from RealtimeHealth::RESTART_DIP_FROM (20) connections, so a quiet night isn't an alarm;
 *    a server that stopped answering after having connections counts as a 100 % drop.
 * A source that couldn't be read is "unknown" for its kind.
 */
final class AlertRules
{
    public static function evaluate(HealthReadingsData $readings, AlertThresholdsData $t): AlertEvaluationData
    {
        $breaches = [];
        $unknown = [];

        $check = function (SystemAlertKind $kind, ?int $value, int $threshold, bool $crossed) use (&$breaches, &$unknown): void {
            if ($value === null) {
                $unknown[] = $kind;
            } elseif ($crossed) {
                $breaches[$kind->value] = ['value' => $value, 'threshold' => $threshold];
            }
        };

        $failed = $readings->failedJobsLastHour;
        $check(SystemAlertKind::FailedJobs, $failed, $t->failedJobsPerHour, $failed !== null && $failed > $t->failedJobsPerHour);

        $missed = $readings->missedSchedules;
        $check(SystemAlertKind::MissedSchedule, $missed, 0, $missed !== null && $missed > 0);

        $signatures = $readings->signatureFailuresLastHour;
        $check(SystemAlertKind::WebhookSignatureFailures, $signatures, $t->signatureFailuresPerHour, $signatures !== null && $signatures >= $t->signatureFailuresPerHour);

        $bounce = self::worstBounce($readings, $t);
        $check(SystemAlertKind::BounceRate, $bounce, $t->bouncePct, $bounce !== null && $bounce > $t->bouncePct);

        $lagMs = $readings->broadcastLag?->lagMs;
        if ($readings->broadcastLag === null) {
            $unknown[] = SystemAlertKind::BroadcastLag;
        } elseif ($lagMs !== null && $lagMs > $t->broadcastLagSeconds * 1000) {
            $breaches[SystemAlertKind::BroadcastLag->value] = ['value' => $lagMs, 'threshold' => $t->broadcastLagSeconds * 1000];
        }

        $drop = self::reverbDropPct($readings);
        if ($readings->reverb === null) {
            $unknown[] = SystemAlertKind::ReverbDrop;
        } elseif ($drop !== null && $drop > $t->reverbDropPct) {
            $breaches[SystemAlertKind::ReverbDrop->value] = ['value' => $drop, 'threshold' => $t->reverbDropPct];
        }

        return new AlertEvaluationData($breaches, $unknown);
    }

    /** The worst bounce rate (whole %, rounded up) among channels with enough volume; 0 when none qualifies; null = unreadable. */
    private static function worstBounce(HealthReadingsData $readings, AlertThresholdsData $t): ?int
    {
        if ($readings->delivery === null) {
            return null;
        }

        $worst = 0;
        foreach ($readings->delivery as $channel) {
            if ($channel->sent >= $t->bounceMinSent) {
                $worst = max($worst, (int) ceil($channel->bounced * 100 / $channel->sent));
            }
        }

        return $worst;
    }

    /** Percentage fall from the recent peak (null = nothing to compare: no peak, or a peak under the floor). */
    private static function reverbDropPct(HealthReadingsData $readings): ?int
    {
        $reverb = $readings->reverb;
        $peak = $reverb?->recentPeak;
        if ($reverb === null || $peak === null || $peak < RealtimeHealth::RESTART_DIP_FROM) {
            return null;
        }

        $current = $reverb->current ?? 0;   // not answering = nobody connected

        return $current >= $peak ? 0 : (int) floor(($peak - $current) * 100 / $peak);
    }
}
