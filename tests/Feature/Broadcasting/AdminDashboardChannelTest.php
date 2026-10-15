<?php

declare(strict_types=1);

use App\Broadcasting\AdminDashboardChannel;
use App\Enums\AdminStatus;
use App\Enums\Metric;
use App\Enums\UserRole;
use App\Events\Admin\AdminDashboardTick;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
| P3.5 — `admin.dashboard` (PRD §9.3): admins with dashboard.view, active, unlocked, 2FA confirmed;
| members never. And the live tick it carries: a metric key and a number, sent when a member
| account is created.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

it('lets admins with dashboard.view join', function (string $role): void {
    expect((new AdminDashboardChannel)->join(adminWithRole($role)))->toBeTrue();
})->with(['super_admin', 'moderator', 'finance']);

it('refuses an admin without dashboard.view, a suspended admin and an admin without 2FA', function (): void {
    $without = adminWithRole('moderator');
    $without->syncRoles([]);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $suspended = adminWithRole('moderator');
    $suspended->forceFill(['status' => AdminStatus::Suspended])->save();

    expect((new AdminDashboardChannel)->join($without))->toBeFalse()
        ->and((new AdminDashboardChannel)->join($suspended))->toBeFalse()
        ->and((new AdminDashboardChannel)->join(adminWithRole('moderator', twoFactor: false)))->toBeFalse();
});

it('never lets a member (web guard user) or a guest join', function (): void {
    expect((new AdminDashboardChannel)->join(User::factory()->create()))->toBeFalse()
        ->and((new AdminDashboardChannel)->join(null))->toBeFalse();
});

it('§9.4: the tick goes to admin.dashboard on the broadcasts queue with a metric key and a number only', function (): void {
    $tick = new AdminDashboardTick(Metric::NewMembers, 7);

    expect($tick->broadcastOn()[0]->name)->toBe('private-admin.dashboard')
        ->and($tick->broadcastAs())->toBe('dashboard.tick')
        ->and($tick->queue)->toBe('broadcasts')
        ->and($tick->broadcastWith())->toBe(['metric' => 'members_new', 'value' => 7]);
});

it('A02: a new member account ticks today\'s signup count — counted once, then +1 each; brokers don\'t tick', function (): void {
    Cache::flush();
    User::factory()->create(['created_at' => now()->subDays(2)]);   // before the fake: an older member
    Event::fake([AdminDashboardTick::class]);

    User::factory()->create();
    User::factory()->create();
    User::factory()->create(['role' => UserRole::Broker]);

    Event::assertDispatchedTimes(AdminDashboardTick::class, 2);
    Event::assertDispatched(AdminDashboardTick::class, fn (AdminDashboardTick $e): bool => $e->metric === Metric::NewMembers && $e->value === 1);
    Event::assertDispatched(AdminDashboardTick::class, fn (AdminDashboardTick $e): bool => $e->value === 2);
});

it('neither a cache problem nor a locked admin breaks anything: the tick is skipped, the account is created', function (): void {
    Illuminate\Support\Facades\Exceptions::fake();
    $broken = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $broken->shouldReceive('has')->andThrow(new RuntimeException('cache is down'));
    app()->when(App\Observers\NewMemberTickObserver::class)->needs(Illuminate\Contracts\Cache\Repository::class)->give(fn () => $broken);
    Event::fake([AdminDashboardTick::class]);

    $user = User::factory()->create();

    expect($user->exists)->toBeTrue();
    Event::assertNotDispatched(AdminDashboardTick::class);
    Illuminate\Support\Facades\Exceptions::assertReported(RuntimeException::class);

    $locked = adminWithRole('moderator');
    $locked->forceFill(['locked_until' => now()->addHour()])->save();
    expect((new AdminDashboardChannel)->join($locked))->toBeFalse();
});

it('§11.0: claiming or releasing a moderation item tells the open queue pages — the queue key only, no name', function (): void {
    seedMasters();
    Event::fake([App\Events\Admin\ModerationClaimChanged::class]);
    $moderator = adminWithRole('moderator');
    $item = App\Models\ModerationItem::factory()->create();

    app(App\Actions\Moderation\ClaimModerationItem::class)->handle($moderator, $item);
    app(App\Actions\Moderation\ClaimModerationItem::class)->release($moderator, $item->fresh());

    Event::assertDispatchedTimes(App\Events\Admin\ModerationClaimChanged::class, 2);
    $event = new App\Events\Admin\ModerationClaimChanged('moderation.profiles');
    expect($event->broadcastOn()[0]->name)->toBe('private-admin.queues')->and($event->broadcastAs())->toBe('queue.claim')
        ->and($event->queue)->toBe('broadcasts')->and($event->broadcastWith())->toBe(['queue' => 'moderation.profiles']);
});

it('a broadcast problem never fails a registration', function (): void {
    Event::listen(AdminDashboardTick::class, fn () => throw new RuntimeException('reverb is down'));
    Illuminate\Support\Facades\Exceptions::fake();

    $user = User::factory()->create();

    expect($user->exists)->toBeTrue();
    Illuminate\Support\Facades\Exceptions::assertReported(RuntimeException::class);
});
