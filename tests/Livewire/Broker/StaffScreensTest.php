<?php

declare(strict_types=1);

use App\Actions\Broker\Staff\InviteStaff;
use App\Data\Broker\StaffInviteData;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Enums\OtpPurpose;
use App\Livewire\Broker\Activity;
use App\Livewire\Broker\Dashboard;
use App\Livewire\Broker\Staff\AcceptInvite;
use App\Livewire\Broker\Staff\Index;
use App\Livewire\Broker\Staff\Show;
use App\Models\Broker;
use App\Models\BrokerStaffInvitation;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P7.5 — the staff screens (§11A B.8 / B.11): Staff\Index (seats, invite, team, invitations),
| Staff\Show (switch-offs, scope, performance, deactivate), Staff\AcceptInvite (password → OTP) and
| Activity. Rendering, validation, locked props, flag-off and empty states, loading markup.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerStaff);
    $this->sms = fakeSms();
});

function ownerActing(): User
{
    return actingAsBureau(bureauLogin());
}

it('Index: seats, the invite form, the team with the empty state, loading markup', function (): void {
    ownerActing();

    Livewire::test(Index::class)
        ->assertSee('0 of 5 seats used')
        ->assertSee('Invite a staff member')
        ->assertSee('Only you so far')
        ->assertSeeHtml('wire:loading');
});

it('Index: inviting shows the invitation; errors stay inline; cancel frees the seat', function (): void {
    ownerActing();

    $page = Livewire::test(Index::class)
        ->set('name', 'Ravi Kumar')->set('phone', '12')->call('invite')->assertHasErrors(['phone'])
        ->set('phone', '9876500011')->set('role', 'MANAGER')->set('scope', 'ALL')->call('invite')
        ->assertHasNoErrors()->assertDispatched('toast')
        ->assertSee('Open invitations')->assertSee('Ravi Kumar')->assertSee('1 of 5 seats used');

    $page->call('cancel', BrokerStaffInvitation::query()->sole()->id)->assertSee('0 of 5 seats used');
});

it('Index: with the broker.staff flag off it explains, and offers no form', function (): void {
    App\Models\FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    ownerActing();

    Livewire::test(Index::class)->assertSee('Staff accounts are coming soon')->assertDontSee('Send invitation');
});

it('Index / Show: staff.manage only — a Manager gets 403', function (): void {
    $owner = bureauLogin();
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: staffOf($owner)->broker);
    actingAsBureau(bureauLogin(BureauRole::Manager, broker: staffOf($owner)->broker));

    Livewire::test(Index::class)->assertForbidden();
    Livewire::test(Show::class, ['code' => staffOf($telecaller)->code])->assertForbidden();
});

it('Show: switch-offs limited to the role, scope, save; the code is locked; another bureau’s code is 404', function (): void {
    $owner = ownerActing();
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: staffOf($owner)->broker);
    $code = staffOf($telecaller)->code;

    $page = Livewire::test(Show::class, ['code' => $code])
        ->assertSee('Switch off: Answer the client inbox')
        ->assertDontSee('Switch off: Export biodata & photos')   // not in a Telecaller's ceiling
        ->set('disabled', ['client_inbox.respond'])->set('scope', 'ASSIGNED')->call('save')
        ->assertDispatched('toast');

    expect(staffOf($telecaller)->disabled_permissions)->toBe(['client_inbox.respond']);
    expect(fn () => $page->set('code', staffOf($owner)->code))->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::test(Show::class, ['code' => staffOf(bureauLogin(BureauRole::Telecaller))->code])->assertNotFound();
});

it('Show: deactivating needs the typed code, then signs them out and returns to the list', function (): void {
    $owner = ownerActing();
    $entry = bureauLogin(BureauRole::DataEntry, broker: staffOf($owner)->broker);
    $code = staffOf($entry)->code;

    Livewire::test(Show::class, ['code' => $code])
        ->set('confirm', 'nope')->call('deactivate')->assertHasErrors(['confirm'])
        ->set('confirm', $code)->call('deactivate')->assertRedirect(route('broker.staff.index'));

    expect(staffOf($entry)->status)->toBe(BureauStaffStatus::Deactivated);
});

it('Show: the Owner’s own row is read-only; performance shows for the chosen month', function (): void {
    $owner = ownerActing();

    Livewire::test(Show::class, ['code' => staffOf($owner)->code])
        ->assertSee('This is your own login')->assertDontSee('Deactivate')
        ->set('month', 'not-a-month')->assertSet('month', now('Asia/Kolkata')->format('Y-m'))
        ->assertSee('Performance —');
});

it('AcceptInvite: password, then the code, then the portal; a bad link shows one generic page', function (): void {
    $owner = bureauLogin();
    app(InviteStaff::class)->handle($owner, new StaffInviteData('Meera', '9876500011', 'TELECALLER', 'ASSIGNED'));
    preg_match('#invite/([A-Za-z0-9]{64})#', $this->sms->notices[0]['variables'][1], $m);
    $token = $m[1];

    Livewire::test(AcceptInvite::class, ['token' => str_repeat('a', 64)])->assertSee('This invite link is no longer valid');

    $page = Livewire::test(AcceptInvite::class, ['token' => $token])
        ->assertSee('Join '.staffOf($owner)->broker->displayName())->assertSee('Meera')
        ->set('password', 'kerala2026')->set('password_confirmation', 'kerala2026')->call('start')
        ->assertSet('step', 'code')->assertSet('password', '');

    expect(fn () => $page->set('token', str_repeat('b', 64)))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $page->set('step', 'password'))->toThrow(CannotUpdateLockedPropertyException::class);

    $page->set('code', '000000')->call('verify')->assertHasErrors(['code'])
        ->set('code', (string) $this->sms->lastCodeFor('+919876500011', OtpPurpose::StaffInvite))->call('verify')
        ->assertRedirect(route('broker.dashboard'));

    expect(auth('web')->user()?->phone)->toBe('+919876500011');
});

it('the invite link is for guests; the route only matches a 64-character token', function (): void {
    $this->get(brokerUrl('/staff/invite/short'))->assertNotFound();
    $this->actingAs(groom(), 'web')->get(brokerUrl('/staff/invite/'.str_repeat('a', 64)))->assertRedirect();
});

it('Activity: renders the empty state and keeps filters in the URL', function (): void {
    ownerActing();

    Livewire::test(Activity::class)->assertSee('No activity found')
        ->set('group', 'bogus')->assertSet('group', '')
        ->set('group', 'staff')->assertSet('group', 'staff')
        ->assertSeeHtml('wire:loading.class="is-loading"');
});

it('Dashboard: the Staff widget shows real seats', function (): void {
    $owner = ownerActing();
    bureauLogin(BureauRole::Telecaller, broker: staffOf($owner)->broker);

    $staff = collect(Livewire::test(Dashboard::class)->viewData('widgets'))->firstWhere('key', 'staff');

    expect($staff['stats']['Seats used'])->toBe('1 / 5');
    expect(Broker::query()->count())->toBe(1);
});
