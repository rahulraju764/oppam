<?php

declare(strict_types=1);

use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\User;

/*
| P0.5 — no admin page without admin auth + a live session + 2FA (PRD §8.1, §8.4, §11.0), and the
| security matrix for the panel: guest, member, no-2FA, suspended, locked, revoked, idle, expired,
| wrong permission, IP allowlist.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

dataset('panel pages', [
    'dashboard' => ['/'],
    'staff' => ['/staff'],
    'roles' => ['/roles'],
    'sessions' => ['/sessions'],
]);

it('serves the panel to a signed-in super admin', function (string $path): void {
    signInAdmin($this, adminWithRole());

    $this->get(adminUrl($path))->assertOk();
})->with('panel pages');

it('sends guests to the admin login', function (string $path): void {
    $this->get(adminUrl($path))->assertRedirect(route('admin.login'));
})->with('panel pages');

it('does not let a signed-in MEMBER into the admin panel', function (string $path): void {
    $this->actingAs(User::factory()->create(), 'web');

    $this->get(adminUrl($path))->assertRedirect(route('admin.login'));
})->with('panel pages');

it('refuses an admin who has not passed 2FA in this session', function (): void {
    $admin = adminWithRole();
    $this->actingAs($admin, 'admin');   // guard user, but no 2FA marker and no registry row

    $this->get(adminUrl('/'))->assertRedirect(route('admin.login'));
    expect(auth('admin')->check())->toBeFalse();
});

it('refuses an admin who never enrolled 2FA even with a session', function (): void {
    signInAdmin($this, adminWithRole('super_admin', twoFactor: false));

    $this->get(adminUrl('/'))->assertRedirect(route('admin.login'));
});

it('signs out a suspended or locked admin on their next request', function (string $state): void {
    $admin = AdminUser::factory()->withTwoFactor()->{$state}()->role('super_admin')->create();
    signInAdmin($this, $admin);

    $this->get(adminUrl('/'))->assertRedirect(route('admin.login'));
})->with(['suspended', 'locked']);

it('signs out a revoked session', function (): void {
    $admin = adminWithRole();
    $session = signInAdmin($this, $admin);
    $session->forceFill(['revoked_at' => now()])->save();

    $this->get(adminUrl('/'))->assertRedirect(route('admin.login'));
});

it('ends a session idle for 30 minutes (A01)', function (): void {
    $admin = adminWithRole();
    $session = AdminSession::factory()->for($admin, 'admin')->create(['last_seen_at' => now()->subMinutes(31)]);
    signInAdmin($this, $admin, $session);

    $this->get(adminUrl('/'))->assertRedirect(route('admin.login'))->assertSessionHas('status');
    expect($session->fresh()->revoked_at)->not->toBeNull();
});

it('ends a session 12 hours after sign-in however active it was (A01)', function (): void {
    $admin = adminWithRole();
    $session = AdminSession::factory()->for($admin, 'admin')->create(['created_at' => now()->subHours(12)->subMinute(), 'last_seen_at' => now()]);
    signInAdmin($this, $admin, $session);

    $this->get(adminUrl('/'))->assertRedirect(route('admin.login'));
});

it('does not accept another admin\'s session registry row', function (): void {
    $victim = adminWithRole();
    $victimSession = AdminSession::factory()->for($victim, 'admin')->create();

    signInAdmin($this, adminWithRole(), $victimSession);   // tampered: points at someone else's row

    $this->get(adminUrl('/'))->assertRedirect(route('admin.login'));
});

it('forbids pages the role lacks the permission for', function (string $path): void {
    signInAdmin($this, adminWithRole('moderator'));

    $this->get(adminUrl($path))->assertForbidden();
})->with(['/staff', '/roles']);

it('blocks the whole admin domain, login page included, outside the IP allowlist', function (): void {
    config(['oppam.admin.ip_allowlist' => ['203.0.113.0/24']]);

    $this->get(adminUrl('/login'))->assertForbidden();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->get(adminUrl('/login'))->assertOk();
});

it('uses a separate, host-only, strict session cookie on the admin domain (PRD §8.1)', function (): void {
    $admin = $this->get(adminUrl('/login'));
    $public = $this->get('/');

    $adminCookie = collect($admin->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === config('oppam.admin.session_cookie'));

    expect($adminCookie)->not->toBeNull()
        ->and($adminCookie->getDomain())->toBeNull()
        ->and($adminCookie->getSameSite())->toBe('strict')
        ->and(collect($public->headers->getCookies())->pluck('name')->all())->not->toContain(config('oppam.admin.session_cookie'));
});

it('keeps Horizon and Pulse behind the admin stack and their permissions', function (string $path, string $permissionRole, int $status): void {
    signInAdmin($this, adminWithRole($permissionRole));

    $this->get(adminUrl($path))->assertStatus($status);
})->with([
    ['/horizon', 'moderator', 403],
    ['/pulse', 'moderator', 403],
    ['/horizon', 'super_admin', 200],
]);

it('hides sidebar links the admin has no permission for', function (): void {
    signInAdmin($this, adminWithRole('moderator'));

    $this->get(adminUrl('/'))
        ->assertOk()
        ->assertDontSee('Admin users')
        ->assertDontSee('Roles &amp; permissions', false)
        ->assertSee('Sessions');
});
