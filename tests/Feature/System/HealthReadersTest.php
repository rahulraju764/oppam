<?php

declare(strict_types=1);

use App\Contracts\RealtimeServerStats;
use App\Data\System\RealtimeStatsData;
use App\Enums\CommsChannel;
use App\Enums\HealthMetric;
use App\Enums\ScheduledRunStatus;
use App\Enums\WebhookOutcome;
use App\Enums\WebhookProvider;
use App\Jobs\System\MeasureBroadcastLag;
use App\Jobs\System\PruneSystemHealth;
use App\Jobs\System\SampleSystemHealth;
use App\Models\HealthSample;
use App\Models\OutboundMessage;
use App\Models\PaymentWebhookEvent;
use App\Models\ScheduledTaskRun;
use App\Models\WebhookReceipt;
use App\Queries\System\DeliveryHealth;
use App\Queries\System\InfrastructureHealth;
use App\Queries\System\QueueHealth;
use App\Queries\System\RealtimeHealth;
use App\Queries\System\RequestHealth;
use App\Queries\System\SchedulerHealth;
use App\Queries\System\WebhookHealth;
use App\Services\Realtime\ReverbServerStats;
use App\Services\System\Pulse\RequestOutcomes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pulse\Facades\Pulse;

/*
| P9.2b1 — A12 system health readers: what each card of the panel is built from, and that a source
| which can't be read gives "unknown" instead of an error.
*/

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(now()->setTime(10, 30));
});

/** A fake WebSocket server answer (null = didn't answer); configured = broadcasting is reverb / pusher. */
function fakeRealtime(?RealtimeStatsData $stats, bool $configured = true): void
{
    app()->instance(RealtimeServerStats::class, new class($stats, $configured) implements RealtimeServerStats
    {
        public function __construct(private readonly ?RealtimeStatsData $stats, private readonly bool $configured) {}

        public function configured(): bool
        {
            return $this->configured;
        }

        public function current(bool $fresh = false): ?RealtimeStatsData
        {
            return $this->stats;
        }
    });
}

// ---- Queues ---------------------------------------------------------------------------------------

it('A12 queues (database driver): waiting, waiting for a retry, oldest wait and failed jobs per queue', function (): void {
    config(['queue.default' => 'database']);
    $job = fn (string $queue, int $attempts, int $availableAgo): array => ['queue' => $queue, 'payload' => '{}', 'attempts' => $attempts,
        'reserved_at' => null, 'available_at' => now()->subSeconds($availableAgo)->getTimestamp(), 'created_at' => now()->getTimestamp()];
    DB::table('jobs')->insert([$job('mail', 0, 30), $job('mail', 2, 300), $job('broadcasts', 0, 5)]);
    $failed = fn (string $queue, $at): array => ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => $queue, 'payload' => '{}', 'exception' => 'x', 'failed_at' => $at];
    DB::table('failed_jobs')->insert([$failed('mail', now()->subMinutes(10)), $failed('mail', now()->subHours(5)), $failed('media', now()->subDays(2))]);

    $health = app(QueueHealth::class)->current();
    $mail = collect($health->queues)->firstWhere('name', 'mail');

    expect($health->connection)->toBe('database')->and($health->horizon)->toBeNull()
        ->and($mail->waiting)->toBe(2)->and($mail->retrying)->toBe(1)->and($mail->waitSeconds)->toBe(300)->and($mail->failed24h)->toBe(2)
        ->and(collect($health->queues)->firstWhere('name', 'imports')->waiting)->toBe(0)
        ->and($health->failed24h)->toBe(2)->and($health->failedLastHour)->toBe(1);   // the 2-day-old failure is outside 24 h
});

// ---- Scheduler ------------------------------------------------------------------------------------

it('A12 scheduler: every task in routes/console.php is listed with its latest run; a missed one is marked', function (): void {
    ScheduledTaskRun::factory()->create(['task' => 'ExpireInterests', 'started_at' => now()->subDays(3)]);   // tracking began 3 days ago
    ScheduledTaskRun::factory()->create(['task' => 'ExpireInterests', 'started_at' => now()->subHours(5)]);  // hourly: missed since
    ScheduledTaskRun::factory()->failed('RuntimeException')->create(['task' => 'ExpireSubscriptions', 'started_at' => now()->subMinutes(2)]);

    $health = app(SchedulerHealth::class)->current();
    $tasks = collect($health->tasks)->keyBy('task');

    expect($tasks)->toHaveKeys(['ExpireInterests', 'ExpireSubscriptions', 'SampleSystemHealth', 'PruneSystemHealth', 'metrics:snapshot --yesterday'])
        ->and($tasks['ExpireInterests']->missed)->toBeTrue()
        ->and($tasks['ExpireSubscriptions']->missed)->toBeFalse()
        ->and($tasks['ExpireSubscriptions']->lastStatus)->toBe(ScheduledRunStatus::Failed)
        ->and($tasks['ExpireSubscriptions']->lastError)->toBe('RuntimeException')
        ->and($tasks['metrics:snapshot --yesterday']->timezone)->toBe('Asia/Kolkata')
        ->and($health->running)->toBeTrue()
        ->and($health->missedCount())->toBeGreaterThan(0);
});

it('A12 scheduler: nothing recorded yet → nothing is "missed" and the scheduler is reported as not running', function (): void {
    $health = app(SchedulerHealth::class)->current();

    expect($health->missedCount())->toBe(0)->and($health->running)->toBeFalse()->and($health->lastRunAt)->toBeNull();
});

it('A12 scheduler: silence for 5 minutes means the scheduler is not running', function (): void {
    ScheduledTaskRun::factory()->create(['started_at' => now()->subMinutes(6)]);

    expect(app(SchedulerHealth::class)->current()->running)->toBeFalse();
});

// ---- Webhooks -------------------------------------------------------------------------------------

it('A12 webhooks: 24-hour counts per provider and outcome; stored payment events not processed, newest first', function (): void {
    WebhookReceipt::factory()->count(3)->create();
    WebhookReceipt::factory()->rejectedSignature()->count(2)->create();
    WebhookReceipt::factory()->create(['provider' => WebhookProvider::Mail, 'outcome' => WebhookOutcome::Error, 'http_status' => 500]);
    WebhookReceipt::factory()->rejectedSignature()->create(['received_at' => now()->subDays(2)]);   // outside 24 h

    PaymentWebhookEvent::factory()->create(['processed_at' => null, 'event_id' => 'evt_old', 'created_at' => now()->subHour()]);
    PaymentWebhookEvent::factory()->create(['processed_at' => null, 'event_id' => 'evt_new', 'created_at' => now()->subMinutes(5)]);
    PaymentWebhookEvent::factory()->create(['processed_at' => null, 'event_id' => 'evt_in_flight', 'created_at' => now()->subSeconds(10)]);
    PaymentWebhookEvent::factory()->create(['event_id' => 'evt_done']);

    $health = app(WebhookHealth::class)->current();
    $razorpay = collect($health->providers)->first(fn ($p) => $p->provider === WebhookProvider::Razorpay);
    $mail = collect($health->providers)->first(fn ($p) => $p->provider === WebhookProvider::Mail);

    expect($razorpay->accepted)->toBe(3)->and($razorpay->rejectedSignature)->toBe(2)->and($razorpay->lastReceivedAt)->not->toBeNull()
        ->and($mail->errors)->toBe(1)
        ->and(collect($health->providers)->first(fn ($p) => $p->provider === WebhookProvider::Msg91)->lastReceivedAt)->toBeNull()
        ->and($health->unprocessedCount)->toBe(2)
        ->and(array_map(fn ($e) => $e->eventId, $health->unprocessed))->toBe(['evt_new', 'evt_old']);
});

// ---- Delivery -------------------------------------------------------------------------------------

it('A12 delivery: last 24 hours per channel; failed-then-bounced counts once, as a bounce', function (): void {
    OutboundMessage::factory()->count(6)->create(['channel' => CommsChannel::Email, 'delivered_at' => now()]);
    OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'failed_at' => now()]);
    OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'failed_at' => now(), 'bounced_at' => now()]);
    OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'bounced_at' => now()]);
    OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'bounced_at' => now(), 'created_at' => now()->subDays(2)]);   // outside 24 h
    OutboundMessage::factory()->create(['channel' => CommsChannel::Sms]);

    [$email, $sms] = app(DeliveryHealth::class)->current();

    expect($email->channel)->toBe(CommsChannel::Email)
        ->and([$email->sent, $email->delivered, $email->failed, $email->bounced])->toBe([9, 6, 1, 2])
        ->and($email->bounceRatePct())->toBe(22.2)
        ->and($sms->sent)->toBe(1);
});

// ---- Requests (Pulse) -----------------------------------------------------------------------------

it('A12 requests: "not recorded" while Pulse is off', function (): void {
    config(['pulse.enabled' => false]);

    expect(app(RequestHealth::class)->current()->recorded)->toBeFalse();
});

it('A12 requests: error rate and p95 for the last hour from the RequestOutcomes recorder', function (): void {
    config(['pulse.enabled' => true]);
    Pulse::startRecording();
    $record = function (string $status, string $bucket, int $times): void {
        for ($i = 0; $i < $times; $i++) {
            Pulse::record(RequestOutcomes::STATUS_TYPE.$status, 'all')->onlyBuckets()->count();
            Pulse::record(RequestOutcomes::DURATION_TYPE.$bucket, 'all')->onlyBuckets()->count();
        }
    };
    $record('2xx', '100', 90);
    $record('2xx', '500', 6);
    $record('5xx', '2500', 4);
    Pulse::ingest();

    $health = app(RequestHealth::class)->current();

    expect($health->recorded)->toBeTrue()->and($health->requests)->toBe(100)->and($health->serverErrors)->toBe(4)
        ->and($health->errorRatePct())->toBe(4.0)
        ->and($health->p95Ms)->toBe(500)->and($health->p95Over)->toBeFalse();
});

it('A12 requests: the recorder counts a request by status class and duration bucket; /up and Pulse itself are ignored', function (): void {
    config(['pulse.enabled' => true]);
    Pulse::startRecording();
    $recorder = app(RequestOutcomes::class);

    $recorder->record(Illuminate\Support\Carbon::now()->subMilliseconds(300), Illuminate\Http\Request::create('/search'), new Symfony\Component\HttpFoundation\Response('', 500));
    $recorder->record(Illuminate\Support\Carbon::now(), Illuminate\Http\Request::create('/up'), new Symfony\Component\HttpFoundation\Response('OK'));
    $recorder->record(Illuminate\Support\Carbon::now(), Illuminate\Http\Request::create('/pulse'), new Symfony\Component\HttpFoundation\Response('OK'));
    Pulse::ingest();

    $health = app(RequestHealth::class)->current();
    expect($health->requests)->toBe(1)->and($health->serverErrors)->toBe(1)->and($health->p95Ms)->toBe(500)
        ->and(DB::table('pulse_aggregates')->where('key', '!=', 'all')->where('type', 'like', 'oppam_%')->count())->toBe(0);   // no URL kept
});

// ---- Reverb + broadcast lag -----------------------------------------------------------------------

it('A12 Reverb: not configured → nothing asked; configured but silent → no stats', function (): void {
    fakeRealtime(null, configured: false);
    expect(app(RealtimeHealth::class)->reverb()->configured)->toBeFalse();

    fakeRealtime(null);
    $reverb = app(RealtimeHealth::class)->reverb();
    expect($reverb->configured)->toBeTrue()->and($reverb->stats)->toBeNull();
});

it('A12 Reverb: the latest detected restart in the last 24 hours', function (): void {
    fakeRealtime(new RealtimeStatsData(40, [], []));
    $sample = fn (?int $value, int $minutesAgo) => HealthSample::factory()->create(['value' => $value, 'sampled_at' => now()->subMinutes($minutesAgo)]);
    $sample(100, 50);
    $sample(null, 40);     // server didn't answer
    $sample(90, 39);       // back → restart detected 39 minutes ago
    $sample(95, 10);
    $sample(5, 9);         // a fall to a twentieth → restart detected 9 minutes ago
    $sample(80, 8);

    expect(app(RealtimeHealth::class)->reverb()->lastRestartAt?->toDateTimeString())->toBe(now()->subMinutes(9)->toDateTimeString());
});

it('A12 broadcast lag: the latest measurement, or the age of a canary still waiting if larger; nothing fresh = unknown', function (): void {
    $lag = fn () => app(RealtimeHealth::class)->broadcastLag();

    expect($lag()->lagMs)->toBeNull();

    HealthSample::factory()->create(['metric' => HealthMetric::BroadcastLagMs, 'value' => 1200, 'sampled_at' => now()->subMinute()]);
    expect($lag()->lagMs)->toBe(1200)->and($lag()->pending)->toBeFalse();

    Cache::put(SampleSystemHealth::CANARY_KEY, (int) floor(microtime(true) * 1000) - 8000, 900);   // waiting 8 s
    expect($lag()->lagMs)->toBeGreaterThanOrEqual(8000)->and($lag()->pending)->toBeTrue();

    Cache::flush();
    HealthSample::query()->update(['sampled_at' => now()->subMinutes(11)]);
    expect($lag()->lagMs)->toBeNull();
});

it('A12 sampler: Reverb connections sampled; a canary sent on broadcasts and measured when a worker runs it', function (): void {
    fakeRealtime(new RealtimeStatsData(42, [], []));

    (new SampleSystemHealth)->handle(app(RealtimeServerStats::class), app('cache.store'));   // queue is sync in tests: the canary runs at once

    expect(HealthSample::query()->where('metric', HealthMetric::ReverbConnections)->sole()->value)->toBe(42)
        ->and(HealthSample::query()->where('metric', HealthMetric::BroadcastLagMs)->sole()->value)->toBeGreaterThanOrEqual(0)
        ->and(Cache::get(SampleSystemHealth::CANARY_KEY))->toBeNull();
});

it('A12 sampler: an unreachable server is sampled as "no answer"; while a canary waits no second one is sent', function (): void {
    Queue::fake();
    fakeRealtime(null);

    $sampler = new SampleSystemHealth;
    $sampler->handle(app(RealtimeServerStats::class), app('cache.store'));
    $sampler->handle(app(RealtimeServerStats::class), app('cache.store'));

    expect(HealthSample::query()->where('metric', HealthMetric::ReverbConnections)->pluck('value')->all())->toBe([null, null]);
    Queue::assertPushedOn('broadcasts', MeasureBroadcastLag::class);
    Queue::assertPushed(MeasureBroadcastLag::class, 1);
});

it('A12 sampler: broadcasting not set up here → no Reverb sample', function (): void {
    Queue::fake();
    fakeRealtime(null, configured: false);

    (new SampleSystemHealth)->handle(app(RealtimeServerStats::class), app('cache.store'));

    expect(HealthSample::query()->where('metric', HealthMetric::ReverbConnections)->count())->toBe(0);
});

it('A12 Reverb stats: no Reverb / Pusher connection → not configured, no HTTP call', function (): void {
    config(['broadcasting.default' => 'null']);
    $stats = app(ReverbServerStats::class);

    expect($stats->configured())->toBeFalse()->and($stats->current())->toBeNull();
});

it('A12 Reverb stats: an unreachable server answers null fast and the failure is cached', function (): void {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => '1',
        'options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false],   // nothing listens on port 9
    ]]);
    $stats = app(ReverbServerStats::class);

    $started = microtime(true);
    expect($stats->current())->toBeNull()->and(microtime(true) - $started)->toBeLessThan(5.0);

    $started = microtime(true);
    expect($stats->current())->toBeNull()->and(microtime(true) - $started)->toBeLessThan(0.5);   // cached "unknown"
});

// ---- Storage + database ---------------------------------------------------------------------------

it('A12 storage and database: free space, disks by name and driver only; size, largest tables and status', function (): void {
    $infra = app(InfrastructureHealth::class);
    $storage = $infra->storage();
    $database = $infra->database();

    expect($storage->freeBytes)->toBeGreaterThan(0)->and($storage->disks)->toHaveKey('local')
        ->and(array_values($storage->disks))->each->toBeIn(['local', 's3', 'ftp', 'sftp', 'scoped', ''])   // drivers only — never a bucket, key or path
        ->and($database->driver)->toBeIn(['mysql', 'mariadb'])
        ->and($database->sizeBytes)->toBeGreaterThan(0)
        ->and(count($database->largestTables))->toBeLessThanOrEqual(InfrastructureHealth::LARGEST_TABLES)
        ->and($database->connections)->toBeGreaterThan(0)
        ->and($database->uptimeSeconds)->toBeGreaterThan(0);
});

// ---- Retention ------------------------------------------------------------------------------------

it('A12 retention: runs and receipts after 30 days, samples after 7 — newer rows kept', function (): void {
    ScheduledTaskRun::factory()->create(['started_at' => now()->subDays(31)]);
    $keptRun = ScheduledTaskRun::factory()->create(['started_at' => now()->subDays(29)]);
    WebhookReceipt::factory()->create(['received_at' => now()->subDays(31)]);
    $keptReceipt = WebhookReceipt::factory()->create(['received_at' => now()->subDays(29)]);
    HealthSample::factory()->create(['sampled_at' => now()->subDays(8)]);
    $keptSample = HealthSample::factory()->create(['sampled_at' => now()->subDays(6)]);

    (new PruneSystemHealth)->handle();

    expect(ScheduledTaskRun::query()->pluck('id')->all())->toBe([$keptRun->id])
        ->and(WebhookReceipt::query()->pluck('id')->all())->toBe([$keptReceipt->id])
        ->and(HealthSample::query()->pluck('id')->all())->toBe([$keptSample->id]);
});
