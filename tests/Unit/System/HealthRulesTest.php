<?php

declare(strict_types=1);

use App\Data\System\DeliveryChannelData;
use App\Domain\System\ChannelTypes;
use App\Domain\System\ScheduleRegistry;
use App\Enums\CommsChannel;
use App\Enums\WebhookOutcome;
use App\Enums\WebhookProvider;
use App\Queries\System\RealtimeHealth;
use App\Queries\System\RequestHealth;
use App\Services\Realtime\ReverbServerStats;
use App\Services\System\Pulse\RequestOutcomes;
use Carbon\CarbonImmutable;

/*
| P9.2b1 — A12 system health: the pure rules behind the panel (no database, no HTTP).
*/

// ---- Reverb per-channel-type counts ---------------------------------------------------------------

it('A12 Reverb: a channel name is reduced to its type (ids never leave the reader)', function (string $name, string $type): void {
    expect(ChannelTypes::of($name))->toBe($type);
})->with([
    ['private-chat.01JABCDEF', ChannelTypes::CHAT],
    ['presence-presence-chat.01JABCDEF', ChannelTypes::CHAT_PRESENCE],
    ['private-inbox.OPM12370', ChannelTypes::INBOX],
    ['presence-presence.online', ChannelTypes::ONLINE],
    ['private-App.Models.User.01JXYZ', ChannelTypes::MEMBER],
    ['private-broker.BRK1042', ChannelTypes::BUREAU],
    ['private-broker-owner.BRK1042', ChannelTypes::BUREAU],
    ['private-admin.queues', ChannelTypes::ADMIN],
    ['private-admin.dashboard', ChannelTypes::ADMIN],
    ['private-announcements', ChannelTypes::ANNOUNCEMENTS],
    ['something-else', ChannelTypes::OTHER],
]);

it('A12 Reverb: the API answer becomes connections + channels / subscribers by type, never a channel name', function (): void {
    $stats = ReverbServerStats::fromApi(['connections' => 42], ['channels' => [
        'private-chat.A' => ['subscription_count' => 2],
        'private-chat.B' => ['subscription_count' => 1],
        'presence-presence.online' => ['user_count' => 30],
        'private-App.Models.User.X' => [],
    ]]);

    expect($stats->connections)->toBe(42)
        ->and($stats->channels)->toBe([ChannelTypes::CHAT => 2, ChannelTypes::ONLINE => 1, ChannelTypes::MEMBER => 1])
        ->and($stats->subscriptions)->toBe([ChannelTypes::CHAT => 3, ChannelTypes::ONLINE => 30, ChannelTypes::MEMBER => 0])
        ->and(serialize($stats))->not->toContain('chat.A');

    expect(ReverbServerStats::fromApi([], [])->connections)->toBe(0);
});

it('A12 Reverb "last restart (detected)": an answer after no answer, or a fall to a tenth from 20+', function (?int $before, int $now, bool $restart): void {
    expect(RealtimeHealth::isRestart($before, $now))->toBe($restart);
})->with([
    'server came back' => [null, 15, true],
    'fell to a tenth' => [200, 20, true],
    'normal dip' => [200, 150, false],
    'small numbers' => [10, 0, false],
    'growth' => [20, 40, false],
]);

// ---- Request latency / error rate ------------------------------------------------------------------

it('A12 requests: status classes and duration buckets', function (): void {
    expect(RequestOutcomes::statusClass(200))->toBe('2xx')
        ->and(RequestOutcomes::statusClass(302))->toBe('3xx')
        ->and(RequestOutcomes::statusClass(419))->toBe('4xx')
        ->and(RequestOutcomes::statusClass(503))->toBe('5xx')
        ->and(RequestOutcomes::bucketOf(0))->toBe('100')
        ->and(RequestOutcomes::bucketOf(100))->toBe('100')
        ->and(RequestOutcomes::bucketOf(101))->toBe('250')
        ->and(RequestOutcomes::bucketOf(5000))->toBe('5000')
        ->and(RequestOutcomes::bucketOf(5001))->toBe(RequestOutcomes::OVER);
});

it('A12 requests: p95 is the bucket edge that holds the 95th percentile', function (array $buckets, ?int $edge, bool $over): void {
    expect(RequestHealth::percentile95($buckets))->toBe([$edge, $over]);
})->with([
    'nothing recorded' => [[], null, false],
    'all fast' => [[100 => 100], 100, false],
    '95 of 100 within 250' => [[100 => 90, 250 => 5, 1000 => 5], 250, false],
    '94 of 100 within 250' => [[100 => 90, 250 => 4, 1000 => 6], 1000, false],
    'tail beyond the last edge' => [[100 => 50, 'over' => 50], null, true],
]);

// ---- Webhooks --------------------------------------------------------------------------------------

it('A12 webhooks: the controller\'s own tag wins, else the status code decides', function (int $status, ?WebhookOutcome $tag, WebhookOutcome $outcome): void {
    expect(WebhookOutcome::fromResponse($status, $tag))->toBe($outcome);
})->with([
    [200, null, WebhookOutcome::Accepted],
    [400, WebhookOutcome::RejectedSignature, WebhookOutcome::RejectedSignature],
    [400, WebhookOutcome::RejectedBody, WebhookOutcome::RejectedBody],
    [403, null, WebhookOutcome::RejectedSignature],
    [422, null, WebhookOutcome::RejectedBody],
    [500, null, WebhookOutcome::Error],
]);

it('A12 webhooks: providers are known by route name only', function (): void {
    expect(WebhookProvider::forRoute('webhooks.razorpay'))->toBe(WebhookProvider::Razorpay)
        ->and(WebhookProvider::forRoute('webhooks.msg91.dlr'))->toBe(WebhookProvider::Msg91)
        ->and(WebhookProvider::forRoute('webhooks.mail.bounce'))->toBe(WebhookProvider::Mail)
        ->and(WebhookProvider::forRoute('home'))->toBeNull()
        ->and(WebhookProvider::forRoute(null))->toBeNull();
});

// ---- Outbound delivery -----------------------------------------------------------------------------

it('A12 delivery: bounce and failure rates against what was sent (none sent = no rate)', function (): void {
    $email = new DeliveryChannelData(CommsChannel::Email, sent: 200, delivered: 180, failed: 4, bounced: 12);

    expect($email->bounceRatePct())->toBe(6.0)
        ->and($email->failureRatePct())->toBe(8.0)
        ->and((new DeliveryChannelData(CommsChannel::Sms, 0, 0, 0, 0))->bounceRatePct())->toBeNull();
});

// ---- Scheduler: missed runs ------------------------------------------------------------------------

it('A12 scheduler: the last due time follows the task\'s own timezone', function (): void {
    $now = CarbonImmutable::parse('2026-11-21 04:00:00', 'UTC');   // 09:30 IST

    expect(ScheduleRegistry::lastDueAt('0 8 * * *', 'Asia/Kolkata', $now)->toDateTimeString())->toBe('2026-11-21 02:30:00')   // 08:00 IST
        ->and(ScheduleRegistry::lastDueAt('5 * * * *', 'UTC', $now)->toDateTimeString())->toBe('2026-11-21 03:05:00')
        ->and(ScheduleRegistry::lastDueAt('* * * * *', 'UTC', $now)->toDateTimeString())->toBe('2026-11-21 04:00:00');
});

it('A12 scheduler: a run is missed only when a due time older than the grace has no run since it', function (?string $lastStarted, ?string $since, bool $missed): void {
    $now = CarbonImmutable::parse('2026-11-21 10:30:00', 'UTC');
    $at = fn (?string $time): ?CarbonImmutable => $time === null ? null : CarbonImmutable::parse($time, 'UTC');

    // hourly at :00, grace 10 → the 10:00 run should have started by 10:10
    expect(ScheduleRegistry::isMissed('0 * * * *', 'UTC', $now, 10, $at($lastStarted), $at($since)))->toBe($missed);
})->with([
    'ran on time' => ['2026-11-21 10:00:02', '2026-11-01 00:00:00', false],
    'last ran an hour before' => ['2026-11-21 09:00:01', '2026-11-01 00:00:00', true],
    'never ran since tracking began' => [null, '2026-11-01 00:00:00', true],
    'nothing recorded at all yet' => [null, null, false],
    'due before tracking began' => [null, '2026-11-21 10:05:00', false],
]);

it('A12 scheduler: within the grace, the current due time is not yet missed', function (): void {
    $now = CarbonImmutable::parse('2026-11-21 10:05:00', 'UTC');   // 5 minutes after 10:00, grace 10

    expect(ScheduleRegistry::isMissed('0 * * * *', 'UTC', $now, 10, CarbonImmutable::parse('2026-11-21 09:00:01', 'UTC'), CarbonImmutable::parse('2026-11-01', 'UTC')))
        ->toBeFalse();
});
