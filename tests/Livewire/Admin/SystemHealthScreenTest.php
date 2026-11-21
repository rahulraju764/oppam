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
