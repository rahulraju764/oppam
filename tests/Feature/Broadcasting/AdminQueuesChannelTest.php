<?php

declare(strict_types=1);

use App\Broadcasting\AdminQueuesChannel;
use App\Enums\AdminStatus;
use App\Models\User;

/*
| P1.3 — `admin.queues` channel authorization (PRD §9.3): admins with moderation.view or
| verification.queue.view, active, 2FA confirmed. Members never join.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

it('lets moderators, verification officers and super admins join', function (string $role): void {
    expect((new AdminQueuesChannel)->join(adminWithRole($role)))->toBeTrue();
})->with(['super_admin', 'moderator', 'verification_officer']);

it('refuses admins without a queue permission', function (string $role): void {
    expect((new AdminQueuesChannel)->join(adminWithRole($role)))->toBeFalse();
})->with(['finance', 'content_editor']);

it('refuses a suspended admin and an admin without 2FA', function (): void {
    $suspended = adminWithRole('moderator');
    $suspended->forceFill(['status' => AdminStatus::Suspended])->save();

    expect((new AdminQueuesChannel)->join($suspended))->toBeFalse()
        ->and((new AdminQueuesChannel)->join(adminWithRole('moderator', twoFactor: false)))->toBeFalse();
});

it('re-checks the database: a permission removed after login no longer joins', function (): void {
    $admin = adminWithRole('moderator');
    $admin->syncRoles([]);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    expect((new AdminQueuesChannel)->join($admin))->toBeFalse();
});

it('never lets a member (web guard user) join', function (): void {
    expect((new AdminQueuesChannel)->join(User::factory()->create()))->toBeFalse();
});

it('refuses a locked admin', function (): void {
    $admin = adminWithRole('moderator');
    $admin->forceFill(['locked_until' => now()->addMinutes(15)])->save();

    expect((new AdminQueuesChannel)->join($admin))->toBeFalse();
});
