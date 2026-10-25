<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\AddClientNote;
use App\Actions\Broker\ManagedProfiles\AssignManagedProfiles;
use App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus;
use App\Actions\Broker\ManagedProfiles\SubmitManagedProfile;
use App\Actions\Broker\ManagedProfiles\UpdateManagedProfile;
use App\Data\Broker\ClientNoteData;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauRole;
use App\Enums\ManagedProfileStatusChange;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Models\Broker;
use App\Models\Profile;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| ★ IdorScopingTest (PRD §11A B.22, P7.3): R-M13-6 / R-M13-18 / R-M13-20. Every managed-profile
| route and Action answers 404 for another bureau's profile, a member's own profile, a claimed
| profile, an unknown code and — for "assigned only" staff — a profile of their bureau that is not
| assigned to them. Nothing is changed by any refused call.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

const MANAGED_ROUTES = ['', '/edit', '/notes'];

/** The profiles a bureau login must never reach. */
function foreignProfiles(Broker $own): array
{
    $other = Broker::factory()->create();

    return [
        'another bureau’s profile' => managedProfile($other, ['status' => ProfileStatus::Draft, 'published_at' => null]),
        'a member’s own profile' => Profile::factory()->female()->create(),
        'a claimed profile (formerly ours)' => managedProfile($own, ['claimed_at' => now(), 'owner_type' => ProfileOwnerType::Self]),
    ];
}

it('★ R-M13-6: every managed-profile page is 404 for profiles outside the bureau', function (string $path): void {
    $login = actingAsBureau(bureauLogin(BureauRole::Owner));

    foreach (foreignProfiles(staffOf($login)->broker) as $case => $profile) {
        $this->get(brokerUrl('/profiles/'.$profile->code.$path))->assertNotFound();
    }

    $this->get(brokerUrl('/profiles/OPM99999999'.$path))->assertNotFound();
})->with(MANAGED_ROUTES);

it('★ R-M13-6: the bureau’s own profile opens', function (string $path): void {
    $login = actingAsBureau(bureauLogin(BureauRole::Owner));
    $profile = managedProfile(staffOf($login)->broker, ['status' => ProfileStatus::Draft, 'published_at' => null]);

    $this->get(brokerUrl('/profiles/'.$profile->code.$path))->assertOk()->assertSee($profile->code);
})->with(MANAGED_ROUTES);

it('★ R-M13-20: "assigned only" staff get 404 for a bureau profile not assigned to them, and see their own', function (string $path): void {
    $owner = bureauLogin(BureauRole::Owner);
    $broker = staffOf($owner)->broker;
    $entry = bureauLogin(BureauRole::DataEntry, broker: $broker);
    staffOf($entry)->forceFill(['profile_scope' => ProfileScope::Assigned])->save();

    $notMine = managedProfile($broker, ['status' => ProfileStatus::Draft, 'published_at' => null]);
    $mine = managedProfile($broker, ['status' => ProfileStatus::Draft, 'published_at' => null], staffOf($entry));
    actingAsBureau($entry->refresh());

    $this->get(brokerUrl('/profiles/'.$notMine->code.$path))->assertNotFound();
    $this->get(brokerUrl('/profiles/'.$mine->code.$path))->assertOk();
})->with(MANAGED_ROUTES);

it('★ R-M13-20: the list shows assigned-only staff their own clients only', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    $broker = staffOf($owner)->broker;
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);
    staffOf($telecaller)->forceFill(['profile_scope' => ProfileScope::Assigned])->save();
    $mine = managedProfile($broker, [], staffOf($telecaller));
    $notMine = managedProfile($broker);
    $foreign = managedProfile(Broker::factory()->create());

    actingAsBureau($telecaller->refresh());
    $this->get(brokerUrl('/profiles'))->assertOk()
        ->assertSee($mine->code)->assertDontSee($notMine->code)->assertDontSee($foreign->code);

    actingAsBureau($owner);
    $this->get(brokerUrl('/profiles'))->assertOk()
        ->assertSee($mine->code)->assertSee($notMine->code)->assertDontSee($foreign->code);
});

it('★ R-M13-6: every managed-profile Action refuses outside codes with 404 and changes nothing', function (string $action): void {
    $login = bureauLogin(BureauRole::Owner);
    $staffId = staffOf(bureauLogin(BureauRole::Telecaller, broker: staffOf($login)->broker))->id;

    foreach (foreignProfiles(staffOf($login)->broker) as $case => $profile) {
        $before = $profile->fresh()?->getAttributes();

        $call = match ($action) {
            'submit' => fn () => app(SubmitManagedProfile::class)->handle($login, $profile->code),
            'deactivate' => fn () => app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Deactivate),
            'delete' => fn () => app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Delete),
            'restore' => fn () => app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Restore),
            'assign' => fn () => app(AssignManagedProfiles::class)->handle($login, [$profile->code], $staffId),
            'client phone' => fn () => app(UpdateManagedProfile::class)->handle($login, $profile->code, '9811122233'),
            'note' => fn () => app(AddClientNote::class)->handle($login, $profile->code, new ClientNoteData('NOTE', null, 'Hello there', null)),
        };

        expect($call)->toThrow(ModelNotFoundException::class, null, $case);
        expect($profile->fresh()?->getAttributes())->toBe($before);
    }
})->with(['submit', 'deactivate', 'delete', 'restore', 'assign', 'client phone', 'note']);

it('★ R-M13-18: the scope takes the bureau from the staff row — never from the request', function (): void {
    $login = bureauLogin(BureauRole::Owner);
    $other = Broker::factory()->create();
    $foreign = managedProfile($other);

    // Even a request that names the other bureau's id finds nothing: the query only uses the login's broker.
    $found = app(ManagedProfileScope::class)->query(staffOf($login))->where('profiles.managed_by_broker_id', $other->id)->get();

    expect($found)->toBeEmpty()
        ->and(app(ManagedProfileScope::class)->covers(staffOf($login), $foreign))->toBeFalse();
});
