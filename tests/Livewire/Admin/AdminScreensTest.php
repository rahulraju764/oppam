<?php

declare(strict_types=1);

use App\Livewire\Admin\Roles\Editor;
use App\Livewire\Admin\Sessions\Index as SessionsIndex;
use App\Livewire\Admin\Staff\Index as StaffIndex;
use App\Models\AdminSession;
use App\Models\AdminUser;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P0.5 — admin screens: render, authorize, validate, and refuse tampering (oppam-testing:
| "Every Livewire component: renders, authorizes, and rejects tampered #[Locked] props").
*/

beforeEach(function (): void {
    seedAdminRoles();
});

it('renders the admin users screen for staff.view and forbids others', function (): void {
    $this->actingAs(adminWithRole(), 'admin');
    Livewire::test(StaffIndex::class)->assertOk()->assertSee('Admin users');

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(StaffIndex::class)->assertForbidden();
});

it('validates an invitation and shows the guardrail message inline', function (): void {
    $this->actingAs(adminWithRole(), 'admin');

    Livewire::test(StaffIndex::class)
        ->set('inviteName', '')
        ->set('inviteEmail', 'not-an-email')
        ->set('inviteRole', 'no_such_role')
        ->call('invite')
        ->assertHasErrors(['inviteName', 'inviteEmail', 'inviteRole']);
});

it('requires a typed reason of at least 10 characters to suspend', function (): void {
    $this->actingAs(adminWithRole(), 'admin');
    $target = adminWithRole('moderator');

    Livewire::test(StaffIndex::class)
        ->call('confirmStatusChange', $target->id)
        ->set('reason', 'short')
        ->call('applyStatusChange')
        ->assertHasErrors(['reason']);

    expect($target->fresh()->isActive())->toBeTrue();
});

it('refuses a tampered target in the status dialog', function (): void {
    $this->actingAs(adminWithRole(), 'admin');

    Livewire::test(StaffIndex::class)->set('targetId', 'someone-else');
})->throws(CannotUpdateLockedPropertyException::class);

it('returns 404 for an unknown admin id', function (): void {
    $this->actingAs(adminWithRole(), 'admin');

    Livewire::test(StaffIndex::class)->call('confirmStatusChange', '01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

it('shows the super_admin role read-only and saves another role within the guardrail', function (): void {
    $this->actingAs(adminWithRole(), 'admin');

    Livewire::test(Editor::class, ['role' => 'super_admin'])
        ->assertSee('always has every permission');

    Livewire::test(Editor::class)
        ->set('role', 'support')
        ->set('selected', ['dashboard.view', 'support.view'])
        ->call('save')
        ->assertDispatched('toast', type: 'success');
});

it('lets a read-only admin view roles but not save them', function (): void {
    $this->actingAs(adminWithRole('read_only'), 'admin');

    Livewire::test(Editor::class)->assertOk()->call('save')->assertForbidden();
});

it('never lets an admin end another admin’s session by id without sessions.revoke_any (IDOR)', function (): void {
    $moderator = adminWithRole('moderator');
    $this->actingAs($moderator, 'admin');
    $victimSession = AdminSession::factory()->for(adminWithRole(), 'admin')->create();

    Livewire::test(SessionsIndex::class)->call('revoke', $victimSession->id)->assertNotFound();

    expect($victimSession->fresh()->revoked_at)->toBeNull();
});

it('lists only your own sessions unless you may revoke any', function (): void {
    $moderator = adminWithRole('moderator');
    AdminSession::factory()->for($moderator, 'admin')->create(['user_agent' => 'Mine']);
    AdminSession::factory()->for(AdminUser::factory()->withTwoFactor()->create(), 'admin')->create(['user_agent' => 'Theirs']);

    $this->actingAs($moderator, 'admin');
    Livewire::test(SessionsIndex::class)->assertSee('Mine')->assertDontSee('Theirs');

    $this->actingAs(adminWithRole(), 'admin');
    Livewire::test(SessionsIndex::class)->assertSee('Mine')->assertSee('Theirs');
});
