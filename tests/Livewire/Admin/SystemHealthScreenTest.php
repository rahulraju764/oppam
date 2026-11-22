<?php

declare(strict_types=1);

use App\Contracts\RealtimeServerStats;
use App\Data\System\RealtimeStatsData;
use App\Domain\System\ChannelTypes;
use App\Livewire\Admin\System\SystemHealth;
use App\Models\AdminUser;
use App\Models\PaymentWebhookEvent;
use App\Models\ScheduledTaskRun;
use App\Models\WebhookReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| P9.2b1 — the A12 system health panel: `system.health.view` (read_only has it; support doesn't),
| read-only, every card renders even when its source can't be read, links to Horizon / Pulse only
| for admins who hold those permissions, and a bounded number of queries.
*/

beforeEach(function (): void {
    seedAdminRoles();
    Cache::flush();
});

function healthAs(string $role): AdminUser
{
    $admin = adminWithRole($role);
    test()->actingAs($admin, 'admin');

    return $admin;
}

/** The WebSocket server's answer for the panel (null = didn't answer). */
function healthRealtime(?RealtimeStatsData $stats, bool $configured = true): void
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

it('A12 health panel: guests go to the admin sign-in', function (): void {
    $this->get(adminUrl('/system/health'))->assertRedirect(route('admin.login'));
});

it('A12 health panel needs system.health.view: 403 without it, 200 with it (and in the sidebar)', function (): void {
    signInAdmin($this, adminWithRole('support'));
    $this->get(adminUrl('/system/health'))->assertForbidden();

    signInAdmin($this, adminWithRole('read_only'));
    $this->get(adminUrl('/system/health'))->assertOk()->assertSee('System health')->assertSee(route('admin.system.health'));
});

it('A12 health panel: the component itself refuses an admin without system.health.view', function (): void {
    healthAs('moderator');

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertForbidden();
});

it('A12 health panel: a refresh after system.health.view is taken away is refused (every render authorizes)', function (): void {
    $admin = healthAs('read_only');
    healthRealtime(null, configured: false);
    $panel = Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk();

    $admin->removeRole('read_only');

    $panel->call('$refresh')->assertForbidden();
});

it('A12 health panel: a section whose read fails says so and the rest of the panel still renders', function (): void {
    healthAs('read_only');
    healthRealtime(null, configured: false);
    $failing = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $failing->shouldReceive('remember')->andThrow(new RuntimeException('lock wait timeout'));
    app()->instance(App\Queries\System\WebhookHealth::class, new App\Queries\System\WebhookHealth($failing));
    app()->instance(App\Queries\System\DeliveryHealth::class, new App\Queries\System\DeliveryHealth($failing));
    app()->instance(App\Queries\System\SchedulerHealth::class, new App\Queries\System\SchedulerHealth(
        app(Illuminate\Console\Scheduling\Schedule::class), app(Illuminate\Contracts\Console\Kernel::class), $failing,
    ));

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSee('Scheduled task runs couldn’t be read right now.')
        ->assertSee('Webhook figures couldn’t be read right now.')
        ->assertSee('Delivery figures couldn’t be read right now.')
        ->assertSee(['Queues', 'Live updates (Reverb)', 'Storage', 'Database'])
        ->assertDontSee('the scheduler does not seem to be running');
});

it('A12 health panel: a failed broadcast-lag / restart read shows "Unavailable", not an error page', function (): void {
    healthAs('read_only');
    healthRealtime(new RealtimeStatsData(5, [], []));
    $failing = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $failing->shouldReceive('get', 'remember')->andThrow(new RuntimeException('cache down'));
    app()->instance(App\Queries\System\RealtimeHealth::class, new App\Queries\System\RealtimeHealth(
        app(RealtimeServerStats::class), app(App\Queries\System\RequestHealth::class), $failing,
    ));

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSee('Broadcast lag')->assertSee('Unavailable')
        ->assertSee('None seen');   // the restart read failed → no restart claimed
});

it('A12 health panel renders every card on an empty system: loading skeleton first, then empty states', function (): void {
    healthAs('read_only');
    healthRealtime(null, configured: false);

    Livewire::test(SystemHealth::class)->assertSeeHtml('ui-skeleton');   // lazy: placeholder first

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSee(['Queues', 'Live updates (Reverb)', 'Scheduled tasks', 'Webhooks (last 24 hours)', 'Outbound email & SMS (last 24 hours)', 'Storage', 'Database'])
        ->assertSee('Live updates are not set up here')
        ->assertSee('No scheduled task has run yet')
        ->assertSee('Nothing waiting')
        ->assertSee('Not recorded (Pulse is off)')
        ->assertSee('Horizon is not used on this connection.')
        ->assertSeeHtml('wire:poll.60s');
});

it('A12 health panel shows live figures: Reverb channels by type, a missed task, signature rejections, unprocessed payment events', function (): void {
    healthAs('read_only');
    healthRealtime(new RealtimeStatsData(57, [ChannelTypes::CHAT => 4, ChannelTypes::ONLINE => 1], [ChannelTypes::CHAT => 7, ChannelTypes::ONLINE => 40]));
    ScheduledTaskRun::factory()->create(['task' => 'ExpireInterests', 'started_at' => now()->subDays(2)]);
    ScheduledTaskRun::factory()->failed('RuntimeException')->create(['task' => 'SampleSystemHealth', 'started_at' => now()->subMinute()]);
    WebhookReceipt::factory()->rejectedSignature()->count(3)->create();
    PaymentWebhookEvent::factory()->create(['processed_at' => null, 'event_id' => 'evt_stuck_1', 'event' => 'payment.captured', 'created_at' => now()->subMinutes(10)]);

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSee('57')->assertSee('Chat')->assertSee('Online presence')
        ->assertSee('Missed')->assertSee('RuntimeException')
        ->assertSee('1 payment webhook was received but not processed.')
        ->assertSee('evt_stuck_1')
        ->assertDontSee('No scheduled task has run');
});

it('A12 health panel: an unreachable live server is said plainly, not an error page', function (): void {
    healthAs('read_only');
    healthRealtime(null);

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSee('The live server did not answer within 2 seconds.');
});

it('A12 health panel links to Horizon and Pulse only for system.horizon / system.pulse', function (): void {
    healthAs('read_only');
    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertDontSee('Open Horizon')->assertDontSee('Open Pulse');

    healthAs('super_admin');
    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertSee('Open Horizon')->assertSee('Open Pulse');
});

it('A12 health panel escapes what it shows (task names, event ids)', function (): void {
    healthAs('read_only');
    ScheduledTaskRun::factory()->create(['task' => '<script>x</script>', 'started_at' => now()]);
    PaymentWebhookEvent::factory()->create(['processed_at' => null, 'event_id' => 'evt_<b>x</b>', 'event' => '<i>e</i>', 'created_at' => now()->subMinutes(5)]);

    Livewire::withoutLazyLoading()->test(SystemHealth::class)
        ->assertDontSeeHtml('<b>x</b>')->assertDontSeeHtml('<i>e</i>')->assertSeeHtml('evt_&lt;b&gt;x&lt;/b&gt;');
});

it('A12 health panel runs a bounded number of queries however much history there is', function (): void {
    healthAs('read_only');
    healthRealtime(null, configured: false);
    $queries = function (): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    ScheduledTaskRun::factory()->count(2)->create();
    WebhookReceipt::factory()->count(2)->create();
    $few = $queries();

    ScheduledTaskRun::factory()->count(30)->sequence(fn ($s) => ['task' => 'task-'.$s->index, 'started_at' => now()->subMinutes($s->index)])->create();
    WebhookReceipt::factory()->count(30)->create();
    PaymentWebhookEvent::factory()->count(25)->create(['processed_at' => null, 'created_at' => now()->subMinutes(5)]);

    expect($queries())->toBeLessThanOrEqual($few + 2);
});

// ---- P9.2b2 — A12 webhook replay from the panel ---------------------------------------------------------

/** A stored payment event whose processing failed once (no order behind it: replay ends "ignored" / refused). */
function stuckWebhook(string $eventId = 'evt_stuck_replay'): PaymentWebhookEvent
{
    return PaymentWebhookEvent::factory()->create([
        'event_id' => $eventId, 'event' => 'payment.authorized', 'processed_at' => null, 'outcome' => null,
        'payload' => json_encode(['entity' => 'event', 'event' => 'payment.authorized', 'contains' => [], 'payload' => []]),
        'failed_attempts' => 2, 'last_failed_at' => now()->subMinutes(3), 'last_error' => 'RuntimeException',
        'created_at' => now()->subMinutes(10),
    ]);
}

it('A12 replay UI: the Replay button and the failed-attempt count — the button only with system.webhooks.replay', function (): void {
    healthRealtime(null, configured: false);
    stuckWebhook();

    healthAs('read_only');
    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSee('evt_stuck_replay')->assertSee('RuntimeException')->assertDontSee('Replay payment webhook')->assertDontSeeHtml('webhook-replay-open');

    healthAs('super_admin');
    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSeeHtml('webhook-replay-open')->assertSee('Replay payment webhook');
});

it('A12 replay UI: a replay from the panel runs the Action, is audited, toasts and resets', function (): void {
    healthRealtime(null, configured: false);
    $event = stuckWebhook();
    healthAs('super_admin');

    Livewire::withoutLazyLoading()->test(SystemHealth::class)
        ->set('replayGateway', $event->gateway)->set('replayEventId', $event->event_id)->set('reason', 'Retry after the outage')
        ->call('replay')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success')
        ->assertSet('reason', '')->assertSet('replayEventId', '');

    expect($event->refresh()->processed_at)->not->toBeNull()->and($event->outcome)->toBe('ignored')
        ->and(App\Models\AuditLog::query()->where('action', 'system.webhook_replayed')->sole()->reason)->toBe('Retry after the outage');
});

it('A12 replay UI security: without the permission the action is refused; a tampered event id is a validation error; an unknown one is a 404', function (): void {
    healthRealtime(null, configured: false);
    $event = stuckWebhook();

    healthAs('read_only');
    Livewire::withoutLazyLoading()->test(SystemHealth::class)
        ->set('replayGateway', $event->gateway)->set('replayEventId', $event->event_id)->set('reason', 'Retry after the outage')
        ->call('replay')->assertForbidden();
    expect($event->refresh()->processed_at)->toBeNull();

    healthAs('super_admin');
    Livewire::withoutLazyLoading()->test(SystemHealth::class)
        ->set('replayGateway', $event->gateway)->set('replayEventId', "evt' OR 1=1 --")->set('reason', 'Retry after the outage')
        ->call('replay')->assertHasErrors(['replayEventId']);

    Livewire::withoutLazyLoading()->test(SystemHealth::class)
        ->set('replayGateway', $event->gateway)->set('replayEventId', 'evt_nobody')->set('reason', 'Retry after the outage')
        ->call('replay')->assertNotFound();
});

it('A12 replay UI: a refusal (short reason → validation; already processed → toast) keeps the row as it was', function (): void {
    healthRealtime(null, configured: false);
    $event = stuckWebhook();
    healthAs('super_admin');

    Livewire::withoutLazyLoading()->test(SystemHealth::class)
        ->set('replayGateway', $event->gateway)->set('replayEventId', $event->event_id)->set('reason', 'no')
        ->call('replay')->assertHasErrors(['reason']);

    $event->forceFill(['processed_at' => now(), 'outcome' => 'activated'])->save();
    Livewire::withoutLazyLoading()->test(SystemHealth::class)
        ->set('replayGateway', $event->gateway)->set('replayEventId', $event->event_id)->set('reason', 'Retry after the outage')
        ->call('replay')->assertDispatched('toast', type: 'danger');
});

// ---- P9.2b2 — A12 alerts on the panel -----------------------------------------------------------------

it('A12 alerts section: open alerts first, then those resolved this week; older ones are not shown; empty state otherwise', function (): void {
    healthAs('read_only');
    healthRealtime(null, configured: false);

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()->assertSee('No alerts');

    App\Models\SystemAlert::factory()->kind(App\Enums\SystemAlertKind::BroadcastLag, 9000, 5000)->resolved(now()->subDays(2))->create();
    App\Models\SystemAlert::factory()->kind(App\Enums\SystemAlertKind::BounceRate, 8, 5)->resolved(now()->subDays(9))->create();
    App\Models\SystemAlert::factory()->create(['value' => 14, 'threshold' => 10, 'clear_since' => now()->subMinute()]);

    Livewire::withoutLazyLoading()->test(SystemHealth::class)->assertOk()
        ->assertSeeInOrder(['Failed jobs', '14 jobs failed in the last hour (alert above 10).', 'Open', 'Clear since', 'Broadcast queue lag', 'Resolved'])
        ->assertDontSee('Email / SMS bounce rate')
        ->assertDontSee('No alerts');
});

it('A12 alerts: the panel and the A02 dashboard listen for .system.alert only for dashboard.view holders', function (): void {
    healthAs('read_only');
    expect(Livewire::withoutLazyLoading()->test(SystemHealth::class)->instance()->getListeners())
        ->toHaveKey('echo-private:admin.dashboard,.system.alert');

    $admin = healthAs('read_only');
    $admin->syncRoles([]);
    $admin->givePermissionTo('system.health.view');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs($admin->fresh(), 'admin');
    expect(Livewire::withoutLazyLoading()->test(SystemHealth::class)->instance()->getListeners())->toBe([]);

    healthAs('super_admin');
    expect(Livewire::test(App\Livewire\Admin\Dashboard::class)->instance()->getListeners())
        ->toHaveKey('echo-private:admin.dashboard,.system.alert');
});
