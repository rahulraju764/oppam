<?php

declare(strict_types=1);

use App\Actions\Admin\Members\DeleteMember;
use App\Enums\PlanCode;
use App\Enums\UserStatus;
use App\Livewire\Admin\Members\Index;
use App\Livewire\Admin\Members\Show;
use App\Livewire\Admin\Members\Tabs\ActivityTab;
use App\Livewire\Admin\Members\Tabs\NotesTab;
use App\Livewire\Admin\Members\Tabs\PhotosTab;
use App\Livewire\Admin\Members\Tabs\ProfileTab;
use App\Livewire\Admin\Members\Tabs\SubscriptionsTab;
use App\Livewire\Admin\Members\Tabs\TimelineTab;
use App\Models\AdminUser;
use App\Models\MemberNote;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P1.7a — A03 screens: member list (search, facets, bulk, export), member page (quick actions,
| typed code), lazy tabs; permissions, 404s and locked props.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
});

function a03As(string $role): AdminUser
{
    $admin = adminWithRole($role);
    test()->actingAs($admin, 'admin');

    return $admin;
}

// ---- List ----------------------------------------------------------------------------------------

it('lists members for anyone with members.view, and forbids the rest', function (): void {
    $member = memberWithPhone('+919833300001');

    a03As('moderator');
    Livewire::test(Index::class)->assertOk()->assertSee($member->profile->code)->assertSee('•••• 0001')->assertDontSee('+919833300001');

    a03As('finance');
    Livewire::test(Index::class)->assertForbidden();
});

it('finds a member by code, phone digits, email or name', function (string $by): void {
    $member = memberWithPhone('+919833300002');
    $member->forceFill(['email' => 'findme@example.com'])->save();
    $member->profile->forceFill(['first_name' => 'Sreelakshmi'])->save();
    $other = memberWithPhone('+919833300003');
    a03As('moderator');

    $term = match ($by) {
        'code' => strtolower($member->profile->code),
        'phone' => '98333 00002',
        'email' => 'FindMe@example.com',
        default => 'Sreelak',
    };

    Livewire::test(Index::class)->set('q', $term)
        ->assertSee($member->profile->code)
        ->assertDontSee($other->profile->code);
})->with(['code', 'phone', 'email', 'name']);

it('treats search wildcards literally', function (): void {
    memberWithPhone('+919833300004');
    a03As('moderator');

    Livewire::test(Index::class)->set('q', '%')->assertSee('No members match');
});

it('hides deleted members unless the Deleted status is chosen', function (): void {
    $kept = memberWithPhone('+919833300005');
    $gone = memberWithPhone('+919833300006');
    app(DeleteMember::class)->handle(adminWithRole(), $gone, $gone->profile->code, 'Member asked us to delete it.');
    a03As('moderator');

    Livewire::test(Index::class)->assertSee($kept->profile->code)->assertDontSee($gone->profile->code)
        ->set('status', 'DELETED')->assertSee($gone->profile->code)->assertDontSee($kept->profile->code);
});

it('filters by plan (current subscription) and ignores unknown filter values', function (): void {
    $gold = memberWithPhone('+919833300007');
    $free = memberWithPhone('+919833300008');
    Subscription::factory()->create(['profile_id' => $gold->profile->id, 'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->value('id')]);
    a03As('moderator');

    Livewire::test(Index::class)->set('plan', 'GOLD')->assertSee($gold->profile->code)->assertDontSee($free->profile->code)
        ->set('plan', 'FREE')->assertSee($free->profile->code)->assertDontSee($gold->profile->code)
        ->set('plan', "GOLD' OR 1=1 --")->assertSee($free->profile->code)->assertSee($gold->profile->code)
        ->set('sort', 'users.password')->assertOk();
});

it('A03 bulk from the list: no delete action exists; a valid suspend works', function (): void {
    $member = memberWithPhone('+919833300009');
    a03As('super_admin');

    Livewire::test(Index::class)
        ->set('selected', [(string) $member->id])
        ->set('bulkAction', 'DELETE')->set('reason', 'Trying a bulk delete.')
        ->call('runBulk')->assertHasErrors(['bulkAction']);
    expect(User::query()->whereKey($member->id)->exists())->toBeTrue();

    Livewire::test(Index::class)
        ->set('selected', [(string) $member->id])
        ->set('bulkAction', 'SUSPEND')->set('reason', 'Spam ring found by the safety team.')
        ->call('runBulk')->assertHasNoErrors()->assertDispatched('toast');
    expect($member->refresh()->status)->toBe(UserStatus::Suspended);
});

it('A03 bulk needs the action\'s permission even when the button is reached', function (): void {
    $member = memberWithPhone('+919833300010');
    a03As('moderator');

    Livewire::test(Index::class)
        ->set('selected', [(string) $member->id])->set('bulkAction', 'SUSPEND')->set('reason', 'No permission for this.')
        ->call('runBulk')->assertForbidden();
    expect($member->refresh()->status)->toBe(UserStatus::Active);
});

it('export: hidden and refused without members.export; a signed link otherwise', function (): void {
    a03As('support');
    Livewire::test(Index::class)->assertDontSee('Export CSV')->call('export')->assertForbidden();

    expect(App\Models\AuditLog::query()->where('action', 'members.export_denied')->count())->toBe(1);

    a03As('super_admin');
    $redirect = Livewire::test(Index::class)->set('q', 'someone@example.com')->call('export')->effects['redirect'] ?? '';
    expect($redirect)->toContain('/members/export')->toContain('signature=')->toContain('token=')
        ->not->toContain('someone')->not->toContain('example.com');   // the search stays on the server
});

// ---- Member page ---------------------------------------------------------------------------------

it('shows a member page; unknown codes 404; the code can\'t be tampered with', function (): void {
    $member = memberWithPhone('+919833300011');
    a03As('moderator');

    Livewire::test(Show::class, ['profile' => $member->profile->code])->assertOk()
        ->assertSee($member->profile->code)->assertSee('+919833300011')
        ->assertDontSee('Suspend')->assertDontSee('Delete');   // moderator: view only
    Livewire::test(Show::class, ['profile' => 'OPM99999999'])->assertNotFound();

    expect(fn () => Livewire::test(Show::class, ['profile' => $member->profile->code])->set('code', 'OPM10001'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('quick actions work from the page and re-check permission on the server', function (): void {
    $member = memberWithPhone('+919833300012');

    a03As('moderator');
    Livewire::test(Show::class, ['profile' => $member->profile->code])
        ->set('reason', 'Moderator tries to suspend.')->call('suspend')->assertForbidden();

    a03As('super_admin');
    Livewire::test(Show::class, ['profile' => $member->profile->code])
        ->assertSee('Suspend')
        ->set('reason', 'Confirmed fake profile.')->call('suspend')
        ->assertDispatched('toast', type: 'success')->assertSee('Reactivate');
    expect($member->refresh()->status)->toBe(UserStatus::Suspended);
});

it('delete from the page needs the exact code', function (): void {
    $member = memberWithPhone('+919833300013');
    a03As('super_admin');

    Livewire::test(Show::class, ['profile' => $member->profile->code])
        ->set('reason', 'Member asked us to delete it.')->set('confirmCode', 'OPM1')
        ->call('delete')->assertHasErrors(['confirmCode']);
    expect($member->refresh()->trashed())->toBeFalse();
});

it('keeps an unknown tab name out of the page', function (): void {
    $member = memberWithPhone('+919833300014');
    a03As('moderator');

    Livewire::withQueryParams(['tab' => '../../etc'])->test(Show::class, ['profile' => $member->profile->code])->assertSet('tab', 'overview');
});

// ---- Tabs ----------------------------------------------------------------------------------------

it('every tab renders for members.view, 404s an unknown code and is forbidden without members.view', function (string $tab): void {
    $member = memberWithPhone('+919833300015');

    a03As('moderator');
    Livewire::test($tab, ['code' => $member->profile->code])->assertOk();
    Livewire::test($tab, ['code' => 'OPM99999999'])->assertNotFound();

    a03As('finance');
    Livewire::test($tab, ['code' => $member->profile->code])->assertForbidden();
})->with([ProfileTab::class, PhotosTab::class, SubscriptionsTab::class, ActivityTab::class, NotesTab::class, TimelineTab::class]);

it('the profile tab shows old → new before saving and puts errors on the fields', function (): void {
    $member = memberThroughStep(6);
    $member->profile->forceFill(['status' => 'ACTIVE', 'published_at' => now()])->save();
    $old = $member->profile->first_name;
    a03As('support');

    Livewire::test(ProfileTab::class, ['code' => $member->profile->code])
        ->set('form.first_name', 'Lakshmi')
        ->assertSee($old)->assertSee('Lakshmi')
        ->set('form.height_cm', '20')->set('reason', 'Correcting typos.')
        ->call('save')->assertHasErrors(['form.height_cm']);

    expect($member->profile()->firstOrFail()->first_name)->toBe($old);
});

it('the notes tab adds a note and shows it; read-only admins can\'t add', function (): void {
    $member = memberWithPhone('+919833300016');

    a03As('support');
    Livewire::test(NotesTab::class, ['code' => $member->profile->code])
        ->set('noteBody', 'Member called about a <script>alert(1)</script> issue.')->call('add')
        ->assertSee('Member called about a')->assertDontSeeHtml('<script>alert(1)</script>');
    expect(MemberNote::query()->count())->toBe(1);

    a03As('read_only');
    Livewire::test(NotesTab::class, ['code' => $member->profile->code])->assertDontSee('Add note')
        ->set('noteBody', 'Trying anyway.')->call('add')->assertForbidden();
});

it('the timeline tab lists the audit rows about this member only', function (): void {
    $member = memberWithPhone('+919833300017');
    $other = memberWithPhone('+919833300018');
    app(App\Actions\Admin\Members\SuspendMember::class)->handle(adminWithRole(), $member, 'Visible on the timeline.');
    app(App\Actions\Admin\Members\SuspendMember::class)->handle(adminWithRole(), $other, 'Not on this timeline.');
    a03As('moderator');

    Livewire::test(TimelineTab::class, ['code' => $member->profile->code])
        ->assertSee('Visible on the timeline.')->assertDontSee('Not on this timeline.');
});

// ---- HTTP ----------------------------------------------------------------------------------------

it('members pages send guests to the admin sign-in', function (string $path): void {
    $this->get(adminUrl($path))->assertRedirect(route('admin.login'));
})->with(['/members', '/members/OPM12370', '/members/export']);

it('serves the members pages to a signed-in admin with members.view', function (): void {
    $member = memberWithPhone('+919833300019');
    signInAdmin($this, adminWithRole('moderator'));

    $this->get(adminUrl('/members'))->assertOk()->assertSee($member->profile->code);
    $this->get(adminUrl('/members/'.$member->profile->code))->assertOk();
    $this->get(adminUrl('/members/'.$member->profile->code.'?tab=notes'))->assertOk();
});
