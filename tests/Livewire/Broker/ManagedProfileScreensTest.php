<?php

declare(strict_types=1);

use App\Enums\BureauRole;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Livewire\Broker\Dashboard;
use App\Livewire\Broker\ManagedProfiles\Form;
use App\Livewire\Broker\ManagedProfiles\Index;
use App\Livewire\Broker\ManagedProfiles\Notes;
use App\Livewire\Broker\ManagedProfiles\Photos;
use App\Livewire\Broker\ManagedProfiles\Show;
use App\Models\Broker;
use App\Models\BrokerClientNote;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| P7.3 — the managed-profile screens (§11A B.6 / B.11): list (filters, bulk assign), the one-sitting
| form (consent, draft-first, sections), detail (status actions, reason), notes, photos, and the
| dashboard widgets. Rendering, validation, actions, locked props, loading / empty states.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

function bureauActing(BureauRole $role = BureauRole::Owner, ?Broker $broker = null): User
{
    return actingAsBureau(bureauLogin($role, broker: $broker));
}

/** Fill the form's Basic section with valid values (the BasicForm strings the browser sends). */
function fillBasics(Testable $form): Testable
{
    $data = basicData();

    foreach (['first_name', 'last_name', 'gender', 'dob', 'marital_status', 'physical_status'] as $field) {
        $form->set('basic.'.$field, (string) $data->{$field});
    }

    return $form->set('basic.height_cm', (string) $data->height_cm)
        ->set('basic.religion_id', (string) $data->religion_id)
        ->set('basic.caste_id', (string) $data->caste_id)
        ->set('basic.mother_tongue_id', (string) $data->mother_tongue_id);
}

// ---- Index ---------------------------------------------------------------------------------------

it('lists the bureau’s clients with status, and the empty state when there are none', function (): void {
    $login = bureauActing();
    Livewire::test(Index::class)->assertSee('No client profiles yet')->assertSee('Add a profile');

    $profile = managedProfile(staffOf($login)->broker, ['first_name' => 'Anjali', 'last_name' => 'Menon']);
    $foreign = managedProfile(Broker::factory()->create());

    Livewire::test(Index::class)
        ->assertSee('Anjali Menon')->assertSee($profile->code)->assertSee('Active')
        ->assertDontSee($foreign->code)
        ->assertSeeHtml('wire:loading.class="is-loading"');
});

it('B.6: filters by status, name / code search and assignee (#[Url]); search wildcards are literal', function (): void {
    $login = bureauActing();
    $broker = staffOf($login)->broker;
    $staff = staffOf(bureauLogin(BureauRole::Telecaller, broker: $broker));
    $active = managedProfile($broker, ['first_name' => 'Anjali']);
    $draft = managedProfile($broker, ['first_name' => 'Divya', 'status' => ProfileStatus::Draft, 'published_at' => null], $staff);

    Livewire::test(Index::class)->set('search', 'Anj')->assertSee($active->code)->assertDontSee($draft->code);
    Livewire::test(Index::class)->set('search', $draft->code)->assertSee($draft->code)->assertDontSee($active->code);
    Livewire::test(Index::class)->set('search', '%')->assertSee('No profiles match');
    Livewire::test(Index::class)->set('assignee', $staff->id)->assertSee($draft->code)->assertDontSee($active->code);
    Livewire::test(Index::class)->set('assignee', 'none')->assertSee($active->code)->assertDontSee($draft->code);
    Livewire::test(Index::class)->set('status', "x' OR 1=1 --")->assertSee($active->code)->assertSee($draft->code);   // unknown → all
    // Last: withQueryParams() stays set for later Livewire::test() calls in this test.
    Livewire::withQueryParams(['status' => 'DRAFT'])->test(Index::class)->assertSee($draft->code)->assertDontSee($active->code);
});

it('B.6: bulk assign from the list (Owner / Manager only)', function (): void {
    $login = bureauActing(BureauRole::Manager);
    $broker = staffOf($login)->broker;
    $staff = staffOf(bureauLogin(BureauRole::Telecaller, broker: $broker));
    $a = managedProfile($broker);
    $b = managedProfile($broker);

    Livewire::test(Index::class)
        ->set('selected', [$a->code, $b->code])->set('assignTo', $staff->id)
        ->call('assignSelected')
        ->assertDispatched('toast')->assertSet('selected', []);

    expect($a->refresh()->assigned_staff_id)->toBe($staff->id)->and($b->refresh()->assigned_staff_id)->toBe($staff->id);
});

it('B.2: a Data entry login sees no bulk assignment, and calling it is refused', function (): void {
    $login = bureauActing(BureauRole::DataEntry);
    $profile = managedProfile(staffOf($login)->broker);

    Livewire::test(Index::class)->assertDontSee('Assign the selected')
        ->set('selected', [$profile->code])->call('assignSelected')->assertForbidden();
});

it('the list is paginated (20 a page)', function (): void {
    $login = bureauActing();
    Profile::factory()->count(22)->brokerManaged(staffOf($login)->broker)->female()->create();

    Livewire::test(Index::class)->assertViewHas('profiles', fn ($p) => $p->count() === 20 && $p->total() === 22)
        ->call('gotoPage', 2)->assertViewHas('profiles', fn ($p) => $p->count() === 2);
});

// ---- Form ----------------------------------------------------------------------------------------

it('B.6: the create form shows the consent wording, locks later sections until the draft exists', function (): void {
    bureauActing(BureauRole::DataEntry);

    Livewire::test(Form::class)
        ->assertSee('I confirm the client has agreed to create this profile and to Oppam Matrimony’s Terms & Privacy Policy.')
        ->assertSee('Save the draft first')->assertSee('Save draft')
        ->call('open', 3)->assertSet('section', 1)
        ->call('autosave');

    expect(Profile::query()->count())->toBe(0);
});

it('R-M13-11: "Save draft" without consent shows the error under the box, nothing written', function (): void {
    bureauActing();

    fillBasics(Livewire::test(Form::class))
        ->set('clientPhone', '9876512345')
        ->call('save')
        ->assertHasErrors(['consent']);

    expect(Profile::query()->count())->toBe(0);
});

it('B.6: "Save draft" with mobile + consent + basics creates the profile and continues with the next section', function (): void {
    $login = bureauActing(BureauRole::DataEntry);

    $form = fillBasics(Livewire::test(Form::class))
        ->set('clientPhone', '98765 12345')->set('consent', true)
        ->call('save')
        ->assertHasNoErrors();

    $profile = Profile::query()->firstOrFail();
    $form->assertRedirect(route('broker.profiles.edit', ['code' => $profile->code, 'section' => 2]));

    expect($profile->owner_type)->toBe(ProfileOwnerType::BrokerManaged)
        ->and($profile->created_by_user_id)->toBe($login->id)
        ->and($profile->client_phone)->toBe('+919876512345');
});

it('B.17: the form shows the M02 field errors (basics) and writes nothing', function (): void {
    bureauActing();

    Livewire::test(Form::class)->set('clientPhone', '9876512345')->set('consent', true)
        ->set('basic.first_name', 'A')
        ->call('save')
        ->assertHasErrors(['basic.first_name', 'basic.gender']);

    expect(Profile::query()->count())->toBe(0);
});

it('edit: saves a section with the M02 Action and opens the next; autosave keeps partial input', function (): void {
    $login = bureauActing(BureauRole::DataEntry);
    $profile = app(App\Actions\Broker\ManagedProfiles\CreateManagedProfile::class)->handle($login, managedData(), basicData());
    $career = careerData();

    $form = Livewire::withQueryParams(['section' => 2])->test(Form::class, ['code' => $profile->code])
        ->assertSet('section', 2)
        ->assertSee('Save & continue');

    foreach ($career->toArray() as $field => $value) {
        if (is_scalar($value)) {
            $form->set('career.'.$field, (string) $value);
        }
    }

    $form->call('save')->assertHasNoErrors()->assertSet('section', 3);
    expect(App\Models\EducationCareer::query()->whereKey($profile->id)->exists())->toBeTrue();
});

it('edit: the code and the open section are locked props', function (string $prop, mixed $value): void {
    $login = bureauActing();
    $profile = managedProfile(staffOf($login)->broker, ['status' => ProfileStatus::Draft, 'published_at' => null]);

    expect(fn () => Livewire::test(Form::class, ['code' => $profile->code])->set($prop, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['code', 'OPM10001'], ['section', 6]]);

it('edit: a profile waiting for review can’t be edited (404), like the member wizard', function (): void {
    $login = bureauActing();
    $profile = managedProfile(staffOf($login)->broker, ['status' => ProfileStatus::PendingReview, 'published_at' => null]);

    Livewire::test(Form::class, ['code' => $profile->code])->assertNotFound();
});

it('edit: submit sends a finished draft to review and goes to the detail page', function (): void {
    $login = bureauActing(BureauRole::DataEntry);
    $profile = managedClientReadyToSubmit($login);

    Livewire::withQueryParams(['section' => 6])->test(Form::class, ['code' => $profile->code])
        ->assertSee('Submit for review')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('broker.profiles.show', ['code' => $profile->code]));

    expect($profile->refresh()->status)->toBe(ProfileStatus::PendingReview);
});

it('edit: the rejection reason is shown', function (): void {
    $login = bureauActing();
    $profile = managedProfile(staffOf($login)->broker, ['status' => ProfileStatus::Rejected, 'published_at' => null]);
    App\Models\ModerationItem::factory()->create(['profile_id' => $profile->id, 'type' => App\Enums\ModerationItemType::ProfileNew,
        'status' => App\Enums\ModerationStatus::Rejected, 'reason_note' => 'Please add a clearer photo.', 'decided_at' => now()]);

    Livewire::test(Form::class, ['code' => $profile->code])->assertSee('Please add a clearer photo.');
    Livewire::test(Show::class, ['code' => $profile->code])->assertSee('Please add a clearer photo.');
});

// ---- Show ----------------------------------------------------------------------------------------

it('show: a Manager deactivates and activates from the detail page; the code is locked', function (): void {
    $login = bureauActing(BureauRole::Manager);
    $profile = managedProfile(staffOf($login)->broker);

    $page = Livewire::test(Show::class, ['code' => $profile->code])
        ->assertSee('Deactivate')->assertSee('Delete')->assertSee('Edit details')
        ->call('change', 'deactivate')->assertDispatched('toast');
    expect($profile->refresh()->status)->toBe(ProfileStatus::Hidden);

    $page->call('change', 'activate');
    expect($profile->refresh()->status)->toBe(ProfileStatus::Active);

    expect(fn () => $page->set('code', 'OPM10001'))->toThrow(CannotUpdateLockedPropertyException::class);
    $page->call('change', 'nonsense')->assertNotFound();
});

it('show: a Telecaller sees the profile but no edit, status or assignment actions — and calls are refused', function (): void {
    $owner = bureauLogin();
    $telecaller = actingAsBureau(bureauLogin(BureauRole::Telecaller, broker: staffOf($owner)->broker));
    $profile = managedProfile(staffOf($owner)->broker, [], staffOf($telecaller));   // Telecallers: assigned clients only (B.2)

    Livewire::test(Show::class, ['code' => $profile->code])
        ->assertSee($profile->code)->assertSee('Notes & follow-ups')
        ->assertDontSee('Edit details')->assertDontSee('Deactivate')->assertDontSee('Assignment')
        ->call('change', 'deactivate')->assertForbidden();

    expect($profile->refresh()->status)->toBe(ProfileStatus::Active);
});

it('show: a deleted profile offers restore with the date it can be restored until', function (): void {
    $login = bureauActing();
    $profile = managedProfile(staffOf($login)->broker);
    $profile->delete();

    Livewire::test(Show::class, ['code' => $profile->code])
        ->assertSee('can be restored until')->assertSee('Restore')->assertDontSee('Edit details')
        ->call('change', 'restore');

    expect($profile->refresh()->trashed())->toBeFalse();
});

it('show: the client mobile is masked', function (): void {
    $login = bureauActing();
    $profile = managedProfile(staffOf($login)->broker, ['client_phone' => '+919876512345']);

    Livewire::test(Show::class, ['code' => $profile->code])->assertSee('+91 98•••••345')->assertDontSee('9876512345');
});

// ---- Notes ---------------------------------------------------------------------------------------

it('notes: empty state, add a call with a follow-up, mark it done', function (): void {
    $login = bureauActing(BureauRole::Telecaller);
    $profile = managedProfile(staffOf($login)->broker, [], staffOf($login));   // Telecallers: assigned clients only (B.2)
    $tomorrow = now(config('oppam.display_timezone'))->addDay()->format('Y-m-d');

    $page = Livewire::test(Notes::class, ['code' => $profile->code])->assertSee('No notes yet')
        ->set('type', 'CALL')->set('outcome', 'Interested')->set('body', 'Spoke to the mother.')->set('followUpOn', $tomorrow)
        ->call('add')->assertHasNoErrors()->assertDispatched('toast')
        ->assertSee('Spoke to the mother.')->assertSee('Mark done')
        ->assertSet('body', '');

    $note = BrokerClientNote::query()->firstOrFail();
    $page->call('complete', $note->id)->assertSee('Done');
    expect($note->refresh()->follow_up_done_at)->not->toBeNull();
});

it('notes: errors show under the fields; the code is locked', function (): void {
    $login = bureauActing();
    $profile = managedProfile(staffOf($login)->broker);

    $page = Livewire::test(Notes::class, ['code' => $profile->code])->set('body', '')->call('add')->assertHasErrors(['body']);
    expect(fn () => $page->set('code', 'OPM10001'))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(BrokerClientNote::query()->count())->toBe(0);
});

// ---- Photos --------------------------------------------------------------------------------------

it('photos: the bureau sees its client’s photos with their review status; the code is locked; others get 404', function (): void {
    $login = bureauActing(BureauRole::DataEntry);
    $profile = managedClientReadyToSubmit($login);

    $page = Livewire::test(Photos::class, ['profileCode' => $profile->code])
        ->assertSee('Profile Photos')->assertSee('Horoscope')
        ->assertViewHas('photos', fn (array $photos): bool => count($photos) === 1);
    expect(fn () => $page->set('profileCode', 'OPM10001'))->toThrow(CannotUpdateLockedPropertyException::class);

    actingAsBureau(bureauLogin(BureauRole::Owner));
    Livewire::test(Photos::class, ['profileCode' => $profile->code])->assertNotFound();
});

// ---- Dashboard -----------------------------------------------------------------------------------

it('B.4: the dashboard counts the caller’s managed profiles and lists today’s follow-ups', function (): void {
    $login = bureauActing();
    $broker = staffOf($login)->broker;
    $profile = managedProfile($broker);
    managedProfile($broker, ['status' => ProfileStatus::Draft, 'published_at' => null]);
    BrokerClientNote::factory()->create(['profile_id' => $profile->id, 'broker_id' => $broker->id, 'author_user_id' => $login->id,
        'follow_up_on' => now(config('oppam.display_timezone'))->format('Y-m-d')]);

    Livewire::test(Dashboard::class)
        ->assertViewHas('widgets', function (array $widgets) use ($profile): bool {
            $byKey = collect($widgets)->keyBy('key');

            return $byKey['profiles']['stats']['Total'] === 2 && $byKey['profiles']['stats']['Draft'] === 1
                && $byKey['followups']['items'][0]['label'] === $profile->code.' · Note';
        });
});

it('B.4: assigned-only staff count only their own clients', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $entry = bureauLogin(BureauRole::DataEntry, broker: $broker);
    staffOf($entry)->forceFill(['profile_scope' => ProfileScope::Assigned])->save();
    managedProfile($broker);
    managedProfile($broker, [], staffOf($entry));
    actingAsBureau($entry->refresh());

    Livewire::test(Dashboard::class)
        ->assertViewHas('widgets', fn (array $w): bool => collect($w)->keyBy('key')['profiles']['stats']['Total'] === 1);
});
