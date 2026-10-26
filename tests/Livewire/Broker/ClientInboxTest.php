<?php

declare(strict_types=1);

use App\Actions\Engagement\SendInterest;
use App\Enums\BureauRole;
use App\Enums\InterestStatus;
use App\Enums\ProfileStatus;
use App\Livewire\Broker\Dashboard;
use App\Livewire\Broker\ManagedProfiles\ClientInbox;
use App\Livewire\Broker\ManagedProfiles\Show;
use App\Models\Broker;
use App\Models\Interest;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P7.4 — the Client Inbox screen (§11A B.6 / B.11 `client_inbox.respond`) and the P7.4 parts of the
| profile page and dashboard: rendering, the tabs, accept / decline / send, locked props, scope,
| empty and loading states, the export links by role, and the live listeners.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

function inboxActing(BureauRole $role = BureauRole::Owner, ?Broker $broker = null): User
{
    return actingAsBureau(bureauLogin($role, broker: $broker));
}

it('renders the waiting interests with name + initial and short facts — never contact details', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker);
    $member = groom();
    $member->profile->forceFill(['first_name' => 'Rahul', 'last_name' => 'Krishnan'])->save();
    app(SendInterest::class)->handle($member->refresh(), $client, 'T01');

    Livewire::test(ClientInbox::class, ['code' => $client->code])
        ->assertOk()
        ->assertSee('Client inbox')
        ->assertSee('Rahul K.')
        ->assertDontSee('Krishnan')
        ->assertDontSee((string) $member->phone)
        ->assertSee('Accept for client')
        ->assertSeeHtml('wire:loading.class="is-loading"')
        ->assertSee('Chat stays closed until the client joins');
});

it('shows the empty state per tab and keeps the tab in the URL (allow-listed)', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker);

    Livewire::test(ClientInbox::class, ['code' => $client->code])
        ->assertSee('Nothing here')
        ->set('tab', 'sent')->assertSee('No interests sent yet')
        ->set('tab', 'bogus')->assertSet('tab', 'pending');
});

it('accept and decline from the screen go through the Action and toast', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker);
    $first = groom();
    $second = groom('+919800000101');
    app(SendInterest::class)->handle($first, $client, 'T01');
    app(SendInterest::class)->handle($second, $client, 'T01');

    Livewire::test(ClientInbox::class, ['code' => $client->code])
        ->call('accept', $first->profile->code)->assertDispatched('toast')
        ->call('decline', $second->profile->code)->assertDispatched('toast')
        ->call('accept', 'OPM99999999')->assertDispatched('toast', type: 'error');

    expect(Interest::query()->where('from_profile_id', $first->profile->id)->value('status'))->toBe(InterestStatus::Accepted)
        ->and(Interest::query()->where('from_profile_id', $second->profile->id)->value('status'))->toBe(InterestStatus::Declined);
});

it('send: validates the code, shows refusals inline, and shows the allowance', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker);
    $target = groom();

    Livewire::test(ClientInbox::class, ['code' => $client->code])
        ->assertSee('used 0 of 100 interests')
        ->call('send')->assertHasErrors(['target' => 'required'])
        ->set('target', 'OPM99999999')->call('send')->assertHasErrors(['target'])
        ->set('target', $target->profile->code)->set('template', 'T01')->call('send')
        ->assertHasNoErrors()->assertDispatched('toast')
        ->assertSee('used 1 of 100 interests');

    expect(Interest::query()->where('from_profile_id', $client->id)->count())->toBe(1);
});

it('send is not offered for a client that isn’t live yet', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker, ['status' => ProfileStatus::PendingReview]);

    Livewire::test(ClientInbox::class, ['code' => $client->code])
        ->assertSee('must be approved and active')
        ->assertDontSee('Send interest');
});

it('the code is locked, and foreign / unassigned clients are 404; Data Entry is 403', function (): void {
    $login = inboxActing();
    $broker = staffOf($login)->broker;
    $client = managedProfile($broker);
    $foreign = managedProfile(Broker::factory()->create());

    $page = Livewire::test(ClientInbox::class, ['code' => $client->code]);
    expect(fn () => $page->set('code', $foreign->code))->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::test(ClientInbox::class, ['code' => $foreign->code])->assertNotFound();

    inboxActing(BureauRole::DataEntry, $broker);
    Livewire::test(ClientInbox::class, ['code' => $client->code])->assertForbidden();

    $telecaller = inboxActing(BureauRole::Telecaller, $broker);
    staffOf($telecaller)->forceFill(['profile_scope' => App\Enums\ProfileScope::Assigned])->save();
    Livewire::test(ClientInbox::class, ['code' => $client->code])->assertNotFound();
});

it('the route is gated by client_inbox.respond (B.11)', function (BureauRole $role, int $status): void {
    $owner = bureauLogin();
    $login = $role === BureauRole::Owner ? $owner : bureauLogin($role, broker: staffOf($owner)->broker);
    $client = managedProfile(staffOf($owner)->broker, [], staffOf($login));   // Telecallers see assigned clients only (B.2)
    actingAsBureau($login);

    $this->get(brokerUrl('/profiles/'.$client->code.'/inbox'))->assertStatus($status);
})->with([
    'owner' => [BureauRole::Owner, 200],
    'manager' => [BureauRole::Manager, 200],
    'telecaller' => [BureauRole::Telecaller, 200],
    'data entry' => [BureauRole::DataEntry, 403],
]);

it('listens on the bureau channel for new interests and claims; a claim of this client leaves the page', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker);
    $brokerId = staffOf($login)->broker_id;

    $page = Livewire::test(ClientInbox::class, ['code' => $client->code]);
    expect($page->instance()->getListeners())->toHaveKeys([
        'echo-private:broker.'.$brokerId.',.client.interest',
        'echo-private:broker.'.$brokerId.',.profile.claimed',
    ]);

    $page->call('claimed', ['profile' => 'OPM00000001'])->assertNoRedirect();
    $page->call('claimed', ['profile' => $client->code])->assertRedirect(route('broker.profiles.index'));
});

// ---- The profile page and the dashboard -------------------------------------------------------------

it('Show: export downloads and the inbox link follow the role (B.2), with the pending count', function (): void {
    $owner = inboxActing();
    $broker = staffOf($owner)->broker;
    $client = managedProfile($broker);
    app(SendInterest::class)->handle(groom(), $client, 'T01');

    Livewire::test(Show::class, ['code' => $client->code])
        ->assertSee('Biodata PDF')
        ->assertSeeHtml(route('broker.profiles.export.biodata', ['code' => $client->code]))
        ->assertSee('Photos can be downloaded once our team approves them.')
        ->assertSee('Client inbox')
        ->assertSeeHtml('1<span class="visually-hidden"> pending</span>');

    $telecaller = inboxActing(BureauRole::Telecaller, $broker);
    $client->forceFill(['assigned_staff_id' => staffOf($telecaller)->id])->save();   // Telecallers: assigned clients only (B.2)
    Livewire::test(Show::class, ['code' => $client->code])->assertDontSee('Biodata PDF')->assertSee('Client inbox');

    inboxActing(BureauRole::DataEntry, $broker);
    Livewire::test(Show::class, ['code' => $client->code])->assertDontSee('Biodata PDF')->assertDontSee('Client inbox');
});

it('Show leaves for the list when this client is claimed', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker);

    Livewire::test(Show::class, ['code' => $client->code])
        ->call('claimed', ['profile' => $client->code])
        ->assertRedirect(route('broker.profiles.index'));
});

it('Dashboard: the Client inbox widget counts pending interests in the caller’s scope', function (): void {
    $login = inboxActing();
    $client = managedProfile(staffOf($login)->broker);
    app(SendInterest::class)->handle(groom(), $client, 'T01');
    app(SendInterest::class)->handle(groom('+919800000101'), managedProfile(Broker::factory()->create()), 'T01');   // another bureau's

    $widgets = Livewire::test(Dashboard::class)->viewData('widgets');
    $inbox = collect($widgets)->firstWhere('key', 'inbox');

    expect($inbox['stats'])->toBe(['Pending interests' => 1]);
});
