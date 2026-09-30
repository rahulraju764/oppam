<?php

declare(strict_types=1);

use App\Actions\Admin\Roles\UpdateRolePermissions;
use App\Actions\Admin\Staff\AcceptAdminInvitation;
use App\Actions\Admin\Staff\ChangeAdminRole;
use App\Actions\Admin\Staff\InviteAdmin;
use App\Actions\Admin\Staff\RevokeAdminSession;
use App\Actions\Admin\Staff\SetAdminStatus;
use App\Enums\AdminStatus;
use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Exceptions\Admin\GuardrailViolation;
use App\Models\AdminInvitation;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Notifications\Admin\AdminInvitationSent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/*
| P0.5 — staff invitations, status, roles and the guardrails of PRD §8.4 / A01:
| can't grant what you don't hold, last super admin protected, not on yourself; every write audited.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

/** A custom role holding exactly these permissions. */
function adminWithPermissions(array $permissions): AdminUser
{
    $role = Role::create(['name' => 'custom_'.uniqid(), 'guard_name' => 'admin']);
    $role->syncPermissions($permissions);

    return adminWithRole($role->name);
}

it('invites staff with a hashed single-use 72 h link and emails it', function (): void {
    Notification::fake();
    $actor = adminWithRole();

    $invitation = app(InviteAdmin::class)->handle($actor, 'Divya', 'Divya@Oppam.test', 'moderator');

    expect($invitation->email)->toBe('divya@oppam.test')
        ->and($invitation->expires_at->between(now()->addHours(71), now()->addHours(73)))->toBeTrue()
        ->and(strlen($invitation->getAttributes()['token_hash']))->toBe(64);

    Notification::assertSentOnDemand(AdminInvitationSent::class, function (AdminInvitationSent $notification, array $channels, object $notifiable) use ($invitation): bool {
        preg_match('#/invitations/([A-Za-z0-9]{64})$#', $notification->url, $match);

        return $notifiable->routes['mail'] === 'divya@oppam.test'
            && isset($match[1])
            && AdminInvitation::hashToken($match[1]) === $invitation->getAttributes()['token_hash'];
    });
    expect(AuditLog::query()->where('action', 'staff.invited')->exists())->toBeTrue();
});

it('refuses an invitation from an admin without staff.invite', function (): void {
    app(InviteAdmin::class)->handle(adminWithRole('moderator'), 'X', 'x@oppam.test', 'read_only');
})->throws(AuthorizationException::class);

it('refuses to invite into a role whose permissions the inviter does not hold', function (): void {
    $inviter = adminWithPermissions(['staff.invite', 'staff.view']);

    app(InviteAdmin::class)->handle($inviter, 'X', 'x@oppam.test', 'finance');
})->throws(GuardrailViolation::class);

it('lets only a super admin invite a super admin', function (): void {
    $inviter = adminWithPermissions(Spatie\Permission\Models\Permission::query()->pluck('name')->all());

    app(InviteAdmin::class)->handle($inviter, 'X', 'x@oppam.test', 'super_admin');
})->throws(GuardrailViolation::class);

it('revokes an older pending invitation when the same email is invited again', function (): void {
    Notification::fake();
    $actor = adminWithRole();
    $first = app(InviteAdmin::class)->handle($actor, 'X', 'x@oppam.test', 'moderator');
    app(InviteAdmin::class)->handle($actor, 'X', 'x@oppam.test', 'support');

    expect($first->fresh()->revoked_at)->not->toBeNull();
});

it('accepts an invitation once: creates the admin with the invited role, 2FA still to enrol', function (): void {
    $token = str_repeat('b', 64);
    AdminInvitation::factory()->token($token)->create(['email' => 'new@oppam.test', 'role' => 'support']);

    $admin = app(AcceptAdminInvitation::class)->handle($token, 'Strong-Password-42');

    expect($admin->hasRole('support'))->toBeTrue()
        ->and($admin->hasConfirmedTwoFactor())->toBeFalse()
        ->and(fn () => app(AcceptAdminInvitation::class)->handle($token, 'Strong-Password-42'))->toThrow(AdminAuthenticationFailed::class);
});

it('refuses expired, revoked and unknown invitation links', function (string $case): void {
    $token = str_repeat('c', 64);
    $invitation = AdminInvitation::factory()->token($token)->create();

    match ($case) {
        'expired' => $invitation->forceFill(['expires_at' => now()->subMinute()])->save(),
        'revoked' => $invitation->forceFill(['revoked_at' => now()])->save(),
        'unknown' => $token = str_repeat('d', 64),
    };

    app(AcceptAdminInvitation::class)->handle($token, 'Strong-Password-42');
})->with(['expired', 'revoked', 'unknown'])->throws(AdminAuthenticationFailed::class);

it('suspends with a reason, ends every session and audits it', function (): void {
    $actor = adminWithRole();
    $target = adminWithRole('moderator');
    $session = AdminSession::factory()->for($target, 'admin')->create();

    app(SetAdminStatus::class)->suspend($actor, $target, 'Left the company');

    expect($target->fresh()->status)->toBe(AdminStatus::Suspended)
        ->and($session->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'staff.suspended')->value('reason'))->toBe('Left the company');
});

it('never lets an admin suspend themselves', function (): void {
    $actor = adminWithRole();

    app(SetAdminStatus::class)->suspend($actor, $actor, 'test');
})->throws(GuardrailViolation::class);

it('never lets a lesser admin suspend or unlock a super admin (must outrank the target)', function (string $operation): void {
    $superAdmin = AdminUser::factory()->withTwoFactor()->locked()->role('super_admin')->create();
    $actor = adminWithPermissions(['staff.suspend', 'staff.view']);

    $operation === 'suspend'
        ? app(SetAdminStatus::class)->suspend($actor, $superAdmin, 'Not allowed')
        : app(SetAdminStatus::class)->reactivate($actor, $superAdmin, 'Not allowed');
})->with(['suspend', 'reactivate'])->throws(GuardrailViolation::class, 'super_admin');

it('only a super admin can demote a super admin — even an admin holding every permission can’t', function (): void {
    $superAdmin = adminWithRole();
    $actor = adminWithPermissions(Spatie\Permission\Models\Permission::query()->pluck('name')->all());

    app(ChangeAdminRole::class)->handle($actor, $superAdmin, 'moderator');
})->throws(GuardrailViolation::class, 'super_admin');

it('does not count a locked super admin as "another active super admin"', function (): void {
    $target = adminWithRole();
    AdminUser::factory()->withTwoFactor()->locked()->role('super_admin')->create();

    expect(fn () => app(App\Domain\Admin\StaffGuardrails::class)->assertNotLastSuperAdmin($target))
        ->toThrow(GuardrailViolation::class, 'last active super admin');
});

it('allows demoting a super admin while another active one remains', function (): void {
    $target = adminWithRole();
    $actor = adminWithRole();

    app(ChangeAdminRole::class)->handle($actor, $target, 'moderator');

    expect($target->fresh()->getRoleNames()->all())->toBe(['moderator']);
});

it('never lets an admin change their own role', function (): void {
    $actor = adminWithRole();

    app(ChangeAdminRole::class)->handle($actor, $actor, 'moderator');
})->throws(GuardrailViolation::class);

it('refuses to take away a role the editor does not fully hold', function (): void {
    $editor = adminWithPermissions(['staff.edit', 'staff.view', 'dashboard.view', 'members.view']);
    $financeAdmin = adminWithRole('finance');

    app(ChangeAdminRole::class)->handle($editor, $financeAdmin, 'read_only');
})->throws(GuardrailViolation::class);

it('reactivating clears a 2FA lock', function (): void {
    $target = AdminUser::factory()->withTwoFactor()->locked()->role('moderator')->create();

    app(SetAdminStatus::class)->reactivate(adminWithRole(), $target, 'Verified by phone');

    expect($target->fresh()->isLocked())->toBeFalse();
});

it('edits a role’s permissions within what the editor holds, and audits the diff', function (): void {
    $editor = adminWithRole();

    app(UpdateRolePermissions::class)->handle($editor, 'support', ['dashboard.view', 'support.view']);

    expect(Role::findByName('support', 'admin')->permissions->pluck('name')->sort()->values()->all())->toBe(['dashboard.view', 'support.view'])
        ->and(AuditLog::query()->where('action', 'roles.updated')->exists())->toBeTrue();
});

it('refuses to grant or remove a permission the editor does not hold', function (): void {
    $editor = adminWithPermissions(['roles.edit', 'roles.view', 'dashboard.view', 'support.view']);

    app(UpdateRolePermissions::class)->handle($editor, 'support', ['dashboard.view', 'support.view', 'billing.refund']);
})->throws(GuardrailViolation::class);

it('never edits the super_admin role', function (): void {
    app(UpdateRolePermissions::class)->handle(adminWithRole(), 'super_admin', []);
})->throws(GuardrailViolation::class);

it('rejects unknown permission keys (tampered request)', function (): void {
    app(UpdateRolePermissions::class)->handle(adminWithRole(), 'support', ['dashboard.view', 'everything.please']);
})->throws(ValidationException::class);

it('lets an admin end their own session but not someone else’s without sessions.revoke_any', function (): void {
    $moderator = adminWithRole('moderator');
    $own = AdminSession::factory()->for($moderator, 'admin')->create();
    $others = AdminSession::factory()->for(adminWithRole(), 'admin')->create();

    app(RevokeAdminSession::class)->handle($moderator, $own);
    expect($own->fresh()->revoked_at)->not->toBeNull()
        ->and(fn () => app(RevokeAdminSession::class)->handle($moderator, $others))->toThrow(AuthorizationException::class);
});

it('creates the first super admin from the console, audited, with 2FA still to enrol', function (): void {
    $this->artisan('oppam:create-super-admin')
        ->expectsQuestion('Name', 'Anand')
        ->expectsQuestion('Work email', 'Anand@Oppam.test')
        ->expectsQuestion('Password (12+ characters, mixed case, a number)', 'Strong-Password-42')
        ->assertSuccessful();

    $admin = AdminUser::query()->where('email', 'anand@oppam.test')->firstOrFail();
    expect($admin->isSuperAdmin())->toBeTrue()
        ->and($admin->hasConfirmedTwoFactor())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'staff.super_admin_created_cli')->exists())->toBeTrue();
});

it('refuses a weak password for the console super admin', function (): void {
    $this->artisan('oppam:create-super-admin')
        ->expectsQuestion('Name', 'Anand')
        ->expectsQuestion('Work email', 'anand@oppam.test')
        ->expectsQuestion('Password (12+ characters, mixed case, a number)', 'weak')
        ->assertFailed();

    expect(AdminUser::query()->where('email', 'anand@oppam.test')->exists())->toBeFalse();
});
