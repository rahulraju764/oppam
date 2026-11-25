<?php

declare(strict_types=1);

use App\Data\System\AlertThresholdsData;
use App\Data\System\BroadcastLagData;
use App\Data\System\DeliveryChannelData;
use App\Data\System\HealthReadingsData;
use App\Data\System\ReverbTrendData;
use App\Domain\System\AlertRules;
use App\Enums\CommsChannel;
use App\Enums\SystemAlertKind;

/*
| P9.2b2 — A12 alert rules (PRD §11 A12): failed jobs > 10, missed schedules, webhook signature
| failures, bounce > 5 %, broadcast lag > 5 s, Reverb connections drop > 30 %. Pure: readings +
| thresholds → crossed / unknown.
*/

function thresholds(): AlertThresholdsData
{
    return new AlertThresholdsData(failedJobsPerHour: 10, signatureFailuresPerHour: 3, bouncePct: 5, bounceMinSent: 50,
        broadcastLagSeconds: 5, reverbDropPct: 30, clearMinutes: 5);
}

/** @param  array<string, mixed>  $overrides */
function readings(array $overrides = []): HealthReadingsData
{
    return new HealthReadingsData(...array_merge([
        'failedJobsLastHour' => 0,
        'missedSchedules' => 0,
        'signatureFailuresLastHour' => 0,
        'delivery' => [new DeliveryChannelData(CommsChannel::Email, 100, 98, 0, 1), new DeliveryChannelData(CommsChannel::Sms, 100, 99, 1, 0)],
        'broadcastLag' => new BroadcastLagData(lagMs: 300, measuredAt: null, pending: false),
        'reverb' => new ReverbTrendData(current: 100, recentPeak: 110),
    ], $overrides));
}

it('A12 alerts: a healthy system crosses nothing and nothing is unknown', function (): void {
    $result = AlertRules::evaluate(readings(), thresholds());

    expect($result->breaches)->toBe([])->and($result->unknown)->toBe([]);
});

it('A12 alerts: failed jobs alert ABOVE the threshold (PRD "> 10"), not at it', function (int $failed, bool $crossed): void {
    $result = AlertRules::evaluate(readings(['failedJobsLastHour' => $failed]), thresholds());

    expect($result->breached(SystemAlertKind::FailedJobs))->toBe($crossed);
    if ($crossed) {
        expect($result->breaches[SystemAlertKind::FailedJobs->value])->toBe(['value' => $failed, 'threshold' => 10]);
    }
})->with(['at 10' => [10, false], 'above (11)' => [11, true], 'none' => [0, false]]);

it('A12 alerts: any missed scheduled task alerts', function (): void {
    expect(AlertRules::evaluate(readings(['missedSchedules' => 1]), thresholds())->breached(SystemAlertKind::MissedSchedule))->toBeTrue()
        ->and(AlertRules::evaluate(readings(['missedSchedules' => 0]), thresholds())->breached(SystemAlertKind::MissedSchedule))->toBeFalse();
});

it('A12 alerts: webhook signature failures alert AT the threshold', function (int $failures, bool $crossed): void {
    expect(AlertRules::evaluate(readings(['signatureFailuresLastHour' => $failures]), thresholds())->breached(SystemAlertKind::WebhookSignatureFailures))->toBe($crossed);
})->with(['2' => [2, false], '3' => [3, true]]);

it('A12 alerts: bounce rate above 5 % alerts — only for a channel that sent the minimum volume', function (array $delivery, ?int $value): void {
    $result = AlertRules::evaluate(readings(['delivery' => $delivery]), thresholds());

    expect($result->breached(SystemAlertKind::BounceRate))->toBe($value !== null);
    if ($value !== null) {
        expect($result->breaches[SystemAlertKind::BounceRate->value]['value'])->toBe($value);
    }
})->with([
    'email 6 of 100' => [[new DeliveryChannelData(CommsChannel::Email, 100, 90, 0, 6)], 6],
    'email exactly 5 %' => [[new DeliveryChannelData(CommsChannel::Email, 100, 95, 0, 5)], null],
    'SMS 5.5 % rounds up to 6' => [[new DeliveryChannelData(CommsChannel::Sms, 200, 180, 0, 11)], 6],
    '1 of 2 — under the minimum volume' => [[new DeliveryChannelData(CommsChannel::Email, 2, 1, 0, 1)], null],
    'nothing sent' => [[], null],
]);

it('A12 alerts: broadcast lag above 5 s alerts (stored in ms); no measurement is UNKNOWN (an open alert stays open)', function (?int $lagMs, bool $crossed, bool $unknown): void {
    $result = AlertRules::evaluate(readings(['broadcastLag' => new BroadcastLagData($lagMs, null, false)]), thresholds());

    expect($result->breached(SystemAlertKind::BroadcastLag))->toBe($crossed)
        ->and($result->isUnknown(SystemAlertKind::BroadcastLag))->toBe($unknown);
})->with(['5 s' => [5000, false, false], '5.1 s' => [5100, true, false], 'not measured' => [null, false, true]]);

it('A12 alerts (review round 1): Reverb down for the whole window (no answered sample) is UNKNOWN, not clear', function (): void {
    $result = AlertRules::evaluate(readings(['reverb' => new ReverbTrendData(current: null, recentPeak: null)]), thresholds());

    expect($result->isUnknown(SystemAlertKind::ReverbDrop))->toBeTrue()->and($result->breached(SystemAlertKind::ReverbDrop))->toBeFalse();
});

it('A12 alerts: Reverb connections dropping more than 30 % from the recent peak alert; a quiet server or no history does not', function (?int $current, ?int $peak, ?int $drop): void {
    $result = AlertRules::evaluate(readings(['reverb' => new ReverbTrendData($current, $peak)]), thresholds());

    expect($result->breached(SystemAlertKind::ReverbDrop))->toBe($drop !== null);
    if ($drop !== null) {
        expect($result->breaches[SystemAlertKind::ReverbDrop->value]['value'])->toBe($drop);
    }
})->with([
    '100 → 69 (31 %)' => [69, 100, 31],
    '100 → 70 (30 %, not above)' => [70, 100, null],
    'server stopped answering after 50' => [null, 50, 100],
    'a quiet night: 15 → 0 (under the 20 floor)' => [0, 15, null],
    'no earlier sample' => [5, null, null],
    'growing' => [150, 100, null],
]);

it('A12 alerts: a source that couldn\'t be read is UNKNOWN for its kind — neither crossed nor clear', function (string $field, SystemAlertKind $kind): void {
    $result = AlertRules::evaluate(readings([$field => null]), thresholds());

    expect($result->isUnknown($kind))->toBeTrue()->and($result->breached($kind))->toBeFalse()
        ->and($result->unknown)->toHaveCount(1);
})->with([
    'failed jobs' => ['failedJobsLastHour', SystemAlertKind::FailedJobs],
    'scheduler' => ['missedSchedules', SystemAlertKind::MissedSchedule],
    'webhooks' => ['signatureFailuresLastHour', SystemAlertKind::WebhookSignatureFailures],
    'delivery' => ['delivery', SystemAlertKind::BounceRate],
    'broadcast lag' => ['broadcastLag', SystemAlertKind::BroadcastLag],
    'reverb (not set up / no fresh sample)' => ['reverb', SystemAlertKind::ReverbDrop],
]);
