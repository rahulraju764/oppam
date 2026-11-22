<?php

declare(strict_types=1);

use App\Actions\System\SyncSystemAlerts;
use App\Data\System\AlertEvaluationData;
use App\Enums\SystemAlertKind;
use App\Events\Admin\SystemAlertChanged;
use App\Jobs\System\CheckSystemHealth;
use App\Jobs\System\PruneSystemHealth;
use App\Models\AdminUser;
use App\Models\SystemAlert;
use App\Models\WebhookReceipt;
use App\Notifications\Admin\SystemAlertOpened;
use App\Queries\System\SchedulerHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| P9.2b2 — A12 alerts: CheckSystemHealth (every minute) → AlertRules → SyncSystemAlerts. An alert opens
| with ONE email to active `system.alerts` holders, stays open (no more email) while crossed, and
| resolves once clear for A15 `system.alert_clear_minutes`. At most one open alert per kind. Unreadable
| sources leave their alert alone. Open / resolve broadcast {kind, state} to admin.dashboard.
*/

beforeEach(function (): void {
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
    Event::fake([SystemAlertChanged::class]);
});

/** @param  array<string, array{value: int, threshold: int}>  $breaches */
function evaluation(array $breaches = [], array $unknown = []): AlertEvaluationData
{
    return new AlertEvaluationData($breaches, $unknown);
}

function failedJobsBreach(int $value = 14): array
{
    return [SystemAlertKind::FailedJobs->value => ['value' => $value, 'threshold' => 10]];
}

function insertFailedJobs(int $count, ?Carbon\CarbonInterface $at = null): void
{
    foreach (range(1, $count) as $_) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => $at ?? now()->subMinutes(10)]);
    }
}

it('A12 alerts: a crossed threshold opens ONE alert and emails active system.alerts holders only', function (): void {
    $super = adminWithRole('super_admin');
    $readOnly = adminWithRole('read_only');
    $suspended = AdminUser::factory()->role('super_admin')->suspended()->create();
    $granted = adminWithRole('support');
    $granted->givePermissionTo('system.alerts');

    $result = app(SyncSystemAlerts::class)->handle(evaluation(failedJobsBreach()), 5);

    $alert = SystemAlert::query()->sole();
    expect($result['opened'])->toBe([SystemAlertKind::FailedJobs])
        ->and($alert->kind)->toBe(SystemAlertKind::FailedJobs)->and($alert->open_key)->toBe('FAILED_JOBS')
        ->and($alert->value)->toBe(14)->and($alert->threshold)->toBe(10)->and($alert->notified_at)->not->toBeNull();

    Notification::assertSentTo([$super, $granted], SystemAlertOpened::class, fn (SystemAlertOpened $n): bool => $n->kind === SystemAlertKind::FailedJobs && $n->value === 14);
    Notification::assertNotSentTo([$readOnly, $suspended], SystemAlertOpened::class);
    Event::assertDispatched(SystemAlertChanged::class, fn (SystemAlertChanged $e): bool => $e->broadcastWith() === ['kind' => 'FAILED_JOBS', 'state' => 'opened']
        && $e->broadcastAs() === 'system.alert' && $e->broadcastOn()[0]->name === 'private-admin.dashboard');
});

it('A12 alerts: while still crossed the alert is updated, never re-emailed', function (): void {
    $super = adminWithRole('super_admin');
    $sync = app(SyncSystemAlerts::class);

    $sync->handle(evaluation(failedJobsBreach(14)), 5);
    $this->travel(1)->minutes();
    $sync->handle(evaluation(failedJobsBreach(25)), 5);

    expect(SystemAlert::query()->sole()->value)->toBe(25);
    Notification::assertSentToTimes($super, SystemAlertOpened::class, 1);
});

it('A12 alerts: a clear condition resolves only after the clear minutes; a value that flaps back keeps the same alert', function (): void {
    adminWithRole('super_admin');
    $sync = app(SyncSystemAlerts::class);
    $sync->handle(evaluation(failedJobsBreach()), 5);

    $sync->handle(evaluation(), 5);                     // clear: noted, still open
    $alert = SystemAlert::query()->sole();
    expect($alert->resolved_at)->toBeNull()->and($alert->clear_since)->not->toBeNull();

    $this->travel(2)->minutes();
    $sync->handle(evaluation(failedJobsBreach()), 5);   // flaps back: same alert, clear forgotten
    expect(SystemAlert::query()->sole()->clear_since)->toBeNull();

    $sync->handle(evaluation(), 5);
    $this->travel(4)->minutes();
    expect($sync->handle(evaluation(), 5)['resolved'])->toBe([]);      // clear for 4 minutes: not yet
    $this->travel(1)->minutes();
    expect($sync->handle(evaluation(), 5)['resolved'])->toBe([SystemAlertKind::FailedJobs]);

    $resolved = SystemAlert::query()->sole();
    expect($resolved->resolved_at)->not->toBeNull()->and($resolved->open_key)->toBeNull();
    Notification::assertSentTimes(SystemAlertOpened::class, 1);
    Event::assertDispatched(SystemAlertChanged::class, fn (SystemAlertChanged $e): bool => $e->state === SystemAlertChanged::RESOLVED);
});

it('A12 alerts: crossing again after it resolved opens a NEW alert with a new email', function (): void {
    $super = adminWithRole('super_admin');
    SystemAlert::factory()->resolved(now()->subHour())->create();

    app(SyncSystemAlerts::class)->handle(evaluation(failedJobsBreach()), 5);

    expect(SystemAlert::query()->count())->toBe(2)->and(SystemAlert::query()->open()->count())->toBe(1);
    Notification::assertSentToTimes($super, SystemAlertOpened::class, 1);
});

it('A12 alerts: at most one open alert per kind — a second insert for an open kind is refused by the database', function (): void {
    SystemAlert::factory()->create();

    expect(fn () => SystemAlert::factory()->create())->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('A12 alerts: an UNKNOWN source neither opens nor resolves its alert', function (): void {
    adminWithRole('super_admin');
    SystemAlert::factory()->create(['clear_since' => now()->subHour()]);

    app(SyncSystemAlerts::class)->handle(evaluation([], [SystemAlertKind::FailedJobs]), 5);

    expect(SystemAlert::query()->sole()->resolved_at)->toBeNull();
    Notification::assertNothingSent();
});

it('A12 CheckSystemHealth: 11 failed jobs in the last hour opens the failed-jobs alert; 10 (or older ones) does not', function (): void {
    $super = adminWithRole('super_admin');
    insertFailedJobs(10);
    insertFailedJobs(5, now()->subHours(3));   // outside the hour

    (new CheckSystemHealth)->handle(...resolveCheck());
    expect(SystemAlert::query()->where('kind', SystemAlertKind::FailedJobs->value)->exists())->toBeFalse();

    insertFailedJobs(1);
    (new CheckSystemHealth)->handle(...resolveCheck());
    expect(SystemAlert::query()->where('kind', SystemAlertKind::FailedJobs->value)->sole()->value)->toBe(11);
    Notification::assertSentTo($super, SystemAlertOpened::class, fn (SystemAlertOpened $n): bool => $n->kind === SystemAlertKind::FailedJobs);
});

it('A12 CheckSystemHealth: 3 webhook signature failures in the last hour open that alert', function (): void {
    adminWithRole('super_admin');
    WebhookReceipt::factory()->rejectedSignature()->count(3)->create(['received_at' => now()->subMinutes(5)]);

    (new CheckSystemHealth)->handle(...resolveCheck());

    expect(SystemAlert::query()->where('kind', SystemAlertKind::WebhookSignatureFailures->value)->sole()->value)->toBe(3);
});

it('A12 CheckSystemHealth: a reader that throws is unknown — its open alert stays open, the others still run', function (): void {
    adminWithRole('super_admin');
    SystemAlert::factory()->kind(SystemAlertKind::MissedSchedule, 1, 0)->create(['clear_since' => now()->subHour()]);
    WebhookReceipt::factory()->rejectedSignature()->count(3)->create(['received_at' => now()->subMinutes(5)]);
    $failing = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $failing->shouldReceive('remember')->andThrow(new RuntimeException('lock wait timeout'));
    app()->instance(SchedulerHealth::class, new SchedulerHealth(app(Illuminate\Console\Scheduling\Schedule::class), app(Illuminate\Contracts\Console\Kernel::class), $failing));

    (new CheckSystemHealth)->handle(...resolveCheck());

    expect(SystemAlert::query()->where('kind', SystemAlertKind::MissedSchedule->value)->sole()->resolved_at)->toBeNull()
        ->and(SystemAlert::query()->where('kind', SystemAlertKind::WebhookSignatureFailures->value)->exists())->toBeTrue();
});

it('A12 CheckSystemHealth runs every minute on the default queue, once per server, without overlap', function (): void {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e): bool => App\Domain\System\ScheduleRegistry::keyOf($e) === 'CheckSystemHealth');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue()
        ->and((new CheckSystemHealth)->queue)->toBe('default');
});

it('A12 alerts: the email names the kind and its numbers, links to system health, and carries no member data', function (): void {
    $mail = (new SystemAlertOpened(SystemAlertKind::BounceRate, 7, 5))->toMail(adminWithRole('super_admin'));

    expect($mail->subject)->toContain('Email / SMS bounce rate')
        ->and(implode(' ', $mail->introLines))->toContain('7 %')->toContain('5 %')
        ->and($mail->actionUrl)->toBe(route('admin.system.health'));
});

it('A12 alerts: every kind has a label, a level and a description with numbers only', function (SystemAlertKind $kind): void {
    expect($kind->label())->not->toBe('')
        ->and($kind->level())->toBeIn(['danger', 'warning'])
        ->and($kind->describe(12, 10))->toMatch('/\d/');
})->with(SystemAlertKind::cases());

it('A12 alerts retention: resolved alerts older than 90 days are pruned; open and recent ones are kept', function (): void {
    SystemAlert::factory()->kind(SystemAlertKind::BounceRate, 7, 5)->resolved(now()->subDays(91))->create(['opened_at' => now()->subDays(92)]);
    $recent = SystemAlert::factory()->kind(SystemAlertKind::BroadcastLag, 9000, 5000)->resolved(now()->subDays(10))->create();
    $open = SystemAlert::factory()->create(['opened_at' => now()->subDays(120)]);

    (new PruneSystemHealth)->handle();

    expect(SystemAlert::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $open->id])->sort()->values()->all());
});

/** The job's handle() dependencies, from the container. */
function resolveCheck(): array
{
    return [
        app(App\Queries\System\QueueHealth::class), app(SchedulerHealth::class), app(App\Queries\System\WebhookHealth::class),
        app(App\Queries\System\DeliveryHealth::class), app(App\Queries\System\RealtimeHealth::class), app(SyncSystemAlerts::class),
    ];
}
