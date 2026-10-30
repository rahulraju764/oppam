<?php

declare(strict_types=1);

use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ImportBatchStatus;
use App\Livewire\Broker\Import\BatchReview;
use App\Livewire\Broker\Import\Upload;
use App\Livewire\Broker\ManagedProfiles\ClientInbox;
use App\Livewire\Broker\ManagedProfiles\Form;
use App\Livewire\Broker\ManagedProfiles\Index;
use App\Livewire\Broker\ManagedProfiles\Notes;
use App\Livewire\Broker\ManagedProfiles\Show;
use App\Livewire\Broker\Staff\Index as StaffIndex;
use App\Livewire\Broker\Staff\Show as StaffShow;
use App\Models\Broker;
use App\Models\BrokerClientNote;
use App\Models\BrokerImportBatch;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;
use App\Models\Profile;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| P7.9 — broker security sweep, mid-flow (PRD §11A B.18 "live checks, no caching of bureau status,
| KYC or staff permissions past a change"; B.19 "staff deactivated while editing", "KYC revoked
| later"; R-M13-21; R-A07-8/10). Each row opens a page while the action is allowed, then the right
| is taken away — the login deactivated, the bureau suspended, KYC revoked, creation paused, or the
| Owner switching the permission off — and the next action must be refused with nothing written.
*/

beforeEach(function (): void {
    seedMasters();
    flagOn(App\Enums\Flag::BrokerBulkImport);
    flagOn(App\Enums\Flag::BrokerStaff);
    fakeSms();
    Illuminate\Support\Facades\Storage::fake((string) config('oppam.media.private_disk'));
    Illuminate\Support\Facades\Queue::fake();
    Illuminate\Support\Facades\Notification::fake();
});

/** @return array<string, Closure(User, Broker): void> */
function revocations(string ...$keys): array
{
    $all = [
        'staff deactivated' => fn (User $login, Broker $b) => staffOf($login)->forceFill(['status' => BureauStaffStatus::Deactivated])->save(),
        'bureau suspended' => fn (User $login, Broker $b) => $b->forceFill(['status' => BrokerStatus::Suspended])->save(),
        'KYC revoked' => fn (User $login, Broker $b) => $b->forceFill(['kyc_status' => BrokerKycStatus::Revoked])->save(),
        'creation paused' => fn (User $login, Broker $b) => $b->forceFill(['creation_paused_at' => now(), 'creation_paused_reason' => 'Disputes'])->save(),
    ];

    return array_intersect_key($all, array_flip($keys));
}

function switchOff(User $login, string $permission): void
{
    staffOf($login)->forceFill(['disabled_permissions' => [$permission]])->save();
}

function sweepBasics(Testable $form): Testable
{
    $data = basicData();
    foreach (['first_name', 'last_name', 'gender', 'dob', 'marital_status', 'physical_status'] as $field) {
        $form->set('basic.'.$field, (string) $data->{$field});
    }

    return $form->set('basic.height_cm', (string) $data->height_cm)->set('basic.religion_id', (string) $data->religion_id)
        ->set('basic.caste_id', (string) $data->caste_id)->set('basic.mother_tongue_id', (string) $data->mother_tongue_id)
        ->set('clientPhone', '9876512399')->set('consent', true);
}

/** Run the call; a refusal is a 403/404 response or an exception — never a write. */
function refused(Closure $call): void
{
    try {
        $test = $call();
        expect($test)->toBeInstanceOf(Testable::class);
        $status = (fn (): int => $this->lastState->getResponse()->status())->call($test);   // Testable keeps it protected
        expect($status)->toBeIn([403, 404]);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBeIn([403, 404]);
    } catch (Illuminate\Database\Eloquent\ModelNotFoundException) {
        // a 404
    }
}

it('★ B.18 add a managed profile (Form::save): refused after the page opened', function (string $how): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $form = sweepBasics(Livewire::test(Form::class));
    $how === 'switched off' ? switchOff($login, 'profiles.create') : revocations($how)[$how]($login, staffOf($login)->broker);

    $form->call('save')->assertStatus(403);
    expect(Profile::query()->where('managed_by_broker_id', staffOf($login)->broker_id)->count())->toBe(0);
})->with(['staff deactivated', 'bureau suspended', 'KYC revoked', 'creation paused', 'switched off']);

it('★ R-A07-9 / R-A07-10 / R-M13-21 upload a sheet (Upload::startImport): refused after the page opened', function (string $how): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $real = importXlsx([importRow()]);
    $upload = Livewire::test(Upload::class)->set('sheet', Illuminate\Http\UploadedFile::fake()->createWithContent('clients.xlsx', (string) file_get_contents((string) $real->getRealPath())));
    $how === 'imports paused'
        ? staffOf($login)->broker->forceFill(['imports_paused_at' => now(), 'imports_paused_reason' => App\Enums\ImportsPauseReason::Admin])->save()
        : revocations($how)[$how]($login, staffOf($login)->broker);

    $response = $upload->call('startImport');
    if ($how === 'imports paused') {
        $response->assertHasErrors('sheet');
    } else {
        $response->assertStatus(403);
    }
    expect(BrokerImportBatch::query()->count())->toBe(0);
})->with(['staff deactivated', 'bureau suspended', 'KYC revoked', 'creation paused', 'imports paused']);

it('★ R-M13-21 / R-A07-10 start an import (BatchReview::import): refused after the batch page opened', function (string $how): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $batch = BrokerImportBatch::factory()->create(['broker_id' => staffOf($login)->broker_id, 'uploaded_by_user_id' => $login->id,
        'status' => ImportBatchStatus::Ready, 'consent_attested_at' => now(), 'consent_attested_by_user_id' => $login->id, 'rows_valid' => 1, 'rows_total' => 1]);
    $page = Livewire::test(BatchReview::class, ['batch' => $batch->code]);
    revocations($how)[$how]($login, staffOf($login)->broker);

    refused(fn () => $page->call('import'));
    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Ready)->and($batch->import_started_at)->toBeNull();
})->with(['staff deactivated', 'bureau suspended', 'KYC revoked', 'creation paused']);

it('★ B.19 change a client’s status (Show::change): refused after the page opened', function (string $how): void {
    $login = actingAsBureau(bureauLogin(BureauRole::Manager));
    $client = managedProfile(staffOf($login)->broker);
    $page = Livewire::test(Show::class, ['code' => $client->code]);
    $how === 'switched off' ? switchOff($login, 'profiles.deactivate') : revocations($how)[$how]($login, staffOf($login)->broker);

    refused(fn () => $page->call('change', 'deactivate'));
    expect($client->refresh()->status)->toBe(App\Enums\ProfileStatus::Active);
})->with(['staff deactivated', 'bureau suspended', 'switched off']);

it('★ B.19 answer the client inbox (ClientInbox::send): refused after the page opened', function (string $how): void {
    flagOn(App\Enums\Flag::BrokerClientInterestSending);
    $login = actingAsBureau(bureauLogin(BureauRole::Telecaller));
    $client = managedProfile(staffOf($login)->broker, [], staffOf($login));
    $page = Livewire::test(ClientInbox::class, ['code' => $client->code])->set('target', Profile::factory()->active()->male()->create()->code);
    $how === 'switched off' ? switchOff($login, 'client_inbox.respond') : revocations($how)[$how]($login, staffOf($login)->broker);

    refused(fn () => $page->call('send'));
    expect(App\Models\Interest::query()->count())->toBe(0);
})->with(['staff deactivated', 'bureau suspended', 'switched off']);

it('★ B.19 write a client note (Notes::add): refused after the page opened', function (string $how): void {
    $login = actingAsBureau(bureauLogin(BureauRole::Telecaller));
    $client = managedProfile(staffOf($login)->broker, [], staffOf($login));
    $page = Livewire::test(Notes::class, ['code' => $client->code])->set('body', 'Called the family');
    $how === 'switched off' ? switchOff($login, 'client_notes.write') : revocations($how)[$how]($login, staffOf($login)->broker);

    refused(fn () => $page->call('add'));
    expect(BrokerClientNote::query()->count())->toBe(0);
})->with(['staff deactivated', 'bureau suspended', 'switched off']);

it('★ R-M13-19 assign clients (Index::assignSelected): refused after the page opened', function (string $how): void {
    $login = actingAsBureau(bureauLogin(BureauRole::Manager));
    $broker = staffOf($login)->broker;
    $client = managedProfile($broker);
    $page = Livewire::test(Index::class)->set('selected', [$client->code])->set('assignTo', staffOf($login)->id);
    $how === 'switched off' ? switchOff($login, 'profiles.assign') : revocations($how)[$how]($login, $broker);

    refused(fn () => $page->call('assignSelected'));
    expect($client->refresh()->assigned_staff_id)->toBeNull();
})->with(['staff deactivated', 'bureau suspended', 'switched off']);

it('★ R-M13-24 the Owner’s staff pages: invite / deactivate refused once the bureau is suspended', function (): void {
    $owner = actingAsBureau(bureauLogin());
    $broker = staffOf($owner)->broker;
    $staff = staffOf(bureauLogin(BureauRole::DataEntry, broker: $broker));
    $index = Livewire::test(StaffIndex::class)->set('name', 'Ravi')->set('phone', '9876599111')->set('role', 'TELECALLER')->set('scope', 'ASSIGNED');
    $show = Livewire::test(StaffShow::class, ['code' => $staff->code]);
    $broker->forceFill(['status' => BrokerStatus::Suspended])->save();

    refused(fn () => $index->call('invite'));
    refused(fn () => $show->call('deactivate'));
    expect(BrokerStaffInvitation::query()->count())->toBe(0)->and($staff->refresh()->status)->toBe(BureauStaffStatus::Active);
});

it('★ R-M13-18 tampered #[Locked] codes on every bureau component that holds one', function (string $component, array $params, string $prop): void {
    $login = actingAsBureau(bureauLogin());
    $broker = staffOf($login)->broker;
    $values = [
        'client' => managedProfile($broker)->code,
        'batch' => BrokerImportBatch::factory()->create(['broker_id' => $broker->id, 'uploaded_by_user_id' => $login->id, 'status' => ImportBatchStatus::Ready])->code,
        'staff' => staffOf(bureauLogin(BureauRole::DataEntry, broker: $broker))->code,
    ];
    $foreign = managedProfile(Broker::factory()->create())->code;

    expect(fn () => Livewire::test($component, array_map(fn (string $v): string => $values[$v] ?? $v, $params))->set($prop, $foreign))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
})->with([
    'Show' => [Show::class, ['code' => 'client'], 'code'],
    'ClientInbox' => [ClientInbox::class, ['code' => 'client'], 'code'],
    'Notes' => [Notes::class, ['code' => 'client'], 'code'],
    'Form (edit)' => [Form::class, ['code' => 'client'], 'code'],
    'Photos' => [App\Livewire\Broker\ManagedProfiles\Photos::class, ['profileCode' => 'client'], 'profileCode'],
    'BatchReview' => [BatchReview::class, ['batch' => 'batch'], 'code'],
    'Staff Show' => [StaffShow::class, ['code' => 'staff'], 'code'],
]);

it('★ R-M13-18 ids sent by the browser are looked up inside the caller’s bureau only (invitation, follow-up, assignee)', function (): void {
    $owner = actingAsBureau(bureauLogin());
    $broker = staffOf($owner)->broker;
    $other = staffOf(bureauLogin())->broker;
    $foreignInvite = BrokerStaffInvitation::factory()->create(['broker_id' => $other->id, 'expires_at' => now()->addDay()]);
    $foreignNote = BrokerClientNote::factory()->create(['profile_id' => managedProfile($other)->id, 'broker_id' => $other->id, 'follow_up_on' => now()->toDateString()]);
    $foreignStaff = BrokerStaff::query()->where('broker_id', $other->id)->firstOrFail();
    $client = managedProfile($broker);

    refused(fn () => Livewire::test(StaffIndex::class)->call('cancel', $foreignInvite->id));
    refused(fn () => Livewire::test(Notes::class, ['code' => $client->code])->call('complete', $foreignNote->id));
    Livewire::test(Index::class)->set('selected', [$client->code])->set('assignTo', $foreignStaff->id)->call('assignSelected')->assertHasErrors('assignTo');

    expect($foreignInvite->refresh()->exists)->toBeTrue()
        ->and($foreignNote->refresh()->completed_at ?? null)->toBeNull()
        ->and($client->refresh()->assigned_staff_id)->toBeNull();
});

it('★ R-M13-6 photo actions: another profile’s photo id sent through the bureau’s own client page is refused; nothing changes', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $client = managedProfile(staffOf($login)->broker);
    $victim = Profile::factory()->create();
    $photo = new App\Models\Media;
    $photo->forceFill(['model_type' => $victim->getMorphClass(), 'model_id' => $victim->id, 'uuid' => (string) Illuminate\Support\Str::uuid(),
        'collection_name' => Profile::PHOTOS, 'name' => 'p', 'file_name' => 'p.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'local', 'size' => 1,
        'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [], 'caption' => 'theirs', 'order_column' => 1])->save();
    $page = fn () => Livewire::test(App\Livewire\Broker\ManagedProfiles\Photos::class, ['profileCode' => $client->code]);   // a fresh page per attack

    refused(fn () => $page()->call('deletePhoto', $photo->uuid));
    refused(fn () => $page()->call('movePhoto', $photo->uuid, 0));
    refused(fn () => $page()->call('updateCaption', $photo->uuid, 'mine now'));

    expect($photo->refresh()->exists)->toBeTrue()->and($photo->caption)->toBe('theirs')->and($photo->model_id)->toBe($victim->id);
    refused(fn () => Livewire::test(App\Livewire\Broker\ManagedProfiles\Photos::class, ['profileCode' => $victim->code]));   // foreign code: 404
});
