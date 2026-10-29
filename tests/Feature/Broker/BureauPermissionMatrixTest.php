<?php

declare(strict_types=1);

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use Database\Seeders\PlansSeeder;

/*
| ★ BureauPermissionMatrixTest (PRD §11A B.22) — P7.1 skeleton: every role × every portal route
| built so far, the B.2 matrix itself (role ceiling, Owner switch-offs, R-M13-19), and the KYC gate
| (R-M13-10 / R-M13-21: 403 BROKER_KYC_REQUIRED). Later sessions add their routes to ROUTES.
*/

/** route path => [permission key, the roles allowed on a verified bureau] */
const BUREAU_ROUTES = [
    '' => ['bureau.dashboard.view', ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER']],
    '/kyc' => ['kyc.manage', ['OWNER']],
    '/profiles/create' => ['profiles.create', ['OWNER', 'MANAGER', 'DATA_ENTRY']],
    '/import' => ['profiles.import', ['OWNER', 'MANAGER', 'DATA_ENTRY']],
    '/import/template.xlsx' => ['profiles.import', ['OWNER', 'MANAGER', 'DATA_ENTRY']],
    '/import/template.csv' => ['profiles.import', ['OWNER', 'MANAGER', 'DATA_ENTRY']],
    '/import/batches' => ['profiles.import', ['OWNER', 'MANAGER', 'DATA_ENTRY']],   // P7.7: role part only (B.9 cancel after KYC revoked)
    // P7.3 managed profiles (the per-profile routes: IdorScopingTest + ManagedProfileScreensTest)
    '/profiles' => ['profiles.view', ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER']],
    // P7.2 referral program
    '/referrals' => ['referrals.view', ['OWNER', 'MANAGER']],
    '/materials' => ['materials.view', ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER']],
    '/materials/qr.svg' => ['materials.view', ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER']],
    '/materials/qr.png' => ['materials.view', ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER']],
    '/materials/poster.pdf' => ['materials.view', ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER']],
    // P7.5 staff accounts, activity log, earnings (B.21: a Telecaller gets 403 on earnings and staff pages by URL)
    '/staff' => ['staff.manage', ['OWNER']],
    '/activity' => ['activity.view', ['OWNER', 'MANAGER']],
    '/earnings' => ['earnings.view', ['OWNER']],
];

/**
 * Per-profile routes (path after /profiles/{code}) => the roles that pass, on a client assigned to
 * the caller (Telecallers are always assigned-only, B.2). The photo package of a client without
 * approved photos answers 409 to the roles that may export (the refusal is logged).
 */
const BUREAU_PROFILE_ROUTES = [
    '' => ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER'],
    '/edit' => ['OWNER', 'MANAGER', 'DATA_ENTRY'],
    '/notes' => ['OWNER', 'MANAGER', 'DATA_ENTRY', 'TELECALLER'],
    '/inbox' => ['OWNER', 'MANAGER', 'TELECALLER'],
    '/export/biodata' => ['OWNER', 'MANAGER'],
    '/export/photos' => ['OWNER', 'MANAGER'],
];

/** The B.2 matrix, row by row (true = in the role's ceiling). Owner | Manager | Data entry | Telecaller. */
const BUREAU_MATRIX = [
    'bureau.dashboard.view' => [true, true, true, true],
    'profiles.view' => [true, true, true, true],
    'profiles.create' => [true, true, true, false],
    'profiles.edit' => [true, true, true, false],
    'profiles.import' => [true, true, true, false],
    'profiles.submit_for_review' => [true, true, true, false],
    'profiles.activate' => [true, true, false, false],
    'profiles.deactivate' => [true, true, false, false],
    'profiles.delete' => [true, true, false, false],
    'profiles.assign' => [true, true, false, false],
    'client_inbox.respond' => [true, true, false, true],
    'client_notes.write' => [true, true, true, true],
    'profiles.export' => [true, true, false, false],
    'referrals.view' => [true, true, false, false],
    'earnings.view' => [true, false, false, false],
    'kyc.manage' => [true, false, false, false],
    'staff.manage' => [true, false, false, false],
    'materials.view' => [true, true, true, true],
    'activity.view' => [true, true, false, false],
];

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(App\Enums\Flag::BrokerBulkImport);   // P7.6 import routes answer only behind their flag
});

it('★ every role × every built portal route on a verified bureau (R-M13-19)', function (BureauRole $role, string $path): void {
    [$permission, $allowed] = BUREAU_ROUTES[$path];
    actingAsBureau(bureauLogin($role));

    $response = $this->get(brokerUrl($path));

    if (in_array($role->value, $allowed, true)) {
        $response->assertOk();
    } else {
        $response->assertForbidden()->assertHeader('X-Error-Code', BureauGate::NOT_PERMITTED);
    }
})->with(BureauRole::cases())->with(array_keys(BUREAU_ROUTES));

it('★ the B.2 matrix: each role holds exactly its ceiling on a verified bureau', function (string $key, array $row): void {
    foreach (BureauRole::cases() as $i => $role) {
        $user = bureauLogin($role);
        expect(app(BureauGate::class)->allows($user, BureauPermission::from($key)))->toBe($row[$i], "{$role->value} × {$key}");
    }
})->with(fn (): array => collect(BUREAU_MATRIX)->map(fn (array $row, string $key): array => [$key, $row])->values()->all());

it('★ every role × every per-client route (B.11): allowed roles open it; the others get 403 BUREAU_PERMISSION', function (BureauRole $role, string $path): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $login = $role === BureauRole::Owner ? $owner : bureauLogin($role, broker: $broker);
    $client = managedProfile($broker, ['status' => App\Enums\ProfileStatus::Draft, 'published_at' => null], staffOf($login));
    actingAsBureau($login);

    $response = $this->get(brokerUrl('/profiles/'.$client->code.$path));

    if (in_array($role->value, BUREAU_PROFILE_ROUTES[$path], true)) {
        $path === '/export/photos' ? $response->assertStatus(409) : $response->assertOk();
    } else {
        $response->assertForbidden()->assertHeader('X-Error-Code', BureauGate::NOT_PERMITTED);
    }
})->with(BureauRole::cases())->with(array_keys(BUREAU_PROFILE_ROUTES));

it('★ every role × the staff detail page: the Owner only (staff.manage)', function (BureauRole $role): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);
    actingAsBureau($role === BureauRole::Owner ? $owner : bureauLogin($role, broker: $broker));

    $response = $this->get(brokerUrl('/staff/'.staffOf($telecaller)->code));

    $role === BureauRole::Owner ? $response->assertOk() : $response->assertForbidden()->assertHeader('X-Error-Code', BureauGate::NOT_PERMITTED);
})->with(BureauRole::cases());

/**
 * Every bureau Action, with the permission its B.2 row grants. Each call runs against a fresh
 * bureau where the caller holds that role; "denied" means a 403 with BUREAU_PERMISSION before
 * anything else happens. What the call does once allowed is the Action's own tests' business.
 *
 * @return array<string, array{0: string, 1: Closure(User, array<string, mixed>): mixed}>
 */
function bureauActions(): array
{
    return [
        'create profile' => ['profiles.create', fn ($u) => app(App\Actions\Broker\ManagedProfiles\CreateManagedProfile::class)->handle($u, managedData('9876599901'), basicData())],
        'change client mobile' => ['profiles.edit', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\UpdateManagedProfile::class)->handle($u, $f['client']->code, '9876599902')],
        'submit for review' => ['profiles.submit_for_review', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\SubmitManagedProfile::class)->handle($u, $f['client']->code)],
        'deactivate profile' => ['profiles.deactivate', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus::class)->handle($u, $f['client']->code, App\Enums\ManagedProfileStatusChange::Deactivate)],
        'activate profile' => ['profiles.activate', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus::class)->handle($u, $f['client']->code, App\Enums\ManagedProfileStatusChange::Activate)],
        'delete profile' => ['profiles.delete', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus::class)->handle($u, $f['client']->code, App\Enums\ManagedProfileStatusChange::Delete)],
        'assign' => ['profiles.assign', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\AssignManagedProfiles::class)->handle($u, [$f['client']->code], null)],
        'restore profile' => ['profiles.delete', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus::class)->handle($u, $f['client']->code, App\Enums\ManagedProfileStatusChange::Restore)],
        'complete follow-up' => ['client_notes.write', fn ($u) => app(App\Actions\Broker\ManagedProfiles\CompleteFollowUp::class)->handle($u, '01HNOTEXISTINGNOTEXXXXXXXX')],
        'add note' => ['client_notes.write', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\AddClientNote::class)->handle($u, $f['client']->code, new App\Data\Broker\ClientNoteData('NOTE', null, 'Spoke to the family', null))],
        'answer client inbox' => ['client_inbox.respond', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\RespondToInterestForClient::class)->handle($u, $f['client']->code, 'OPM99999999', false)],
        'send interest for client' => ['client_inbox.respond', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\SendInterestForClient::class)->handle($u, $f['client']->code, 'OPM99999999', null)],
        'export' => ['profiles.export', fn ($u, $f) => app(App\Actions\Broker\ManagedProfiles\ExportManagedProfile::class)->handle($u, $f['client']->code, App\Enums\BrokerExportType::BiodataPdf, null)],
        'invite staff' => ['staff.manage', fn ($u) => app(App\Actions\Broker\Staff\InviteStaff::class)->handle($u, new App\Data\Broker\StaffInviteData('Ravi', '9876599903', 'TELECALLER', 'ASSIGNED'))],
        'change staff permissions' => ['staff.manage', fn ($u, $f) => app(App\Actions\Broker\Staff\UpdateStaffPermissions::class)->handle($u, $f['staff']->code, [], 'ALL')],
        'deactivate staff' => ['staff.manage', fn ($u, $f) => app(App\Actions\Broker\Staff\DeactivateStaff::class)->handle($u, $f['staff']->code)],
        'cancel invitation' => ['staff.manage', fn ($u, $f) => app(App\Actions\Broker\Staff\CancelInvitation::class)->handle($u, $f['invitation']->id)],
        'upload an import' => ['profiles.import', fn ($u) => app(App\Actions\Broker\Import\StoreImportBatch::class)->handle($u, importXlsx([importRow()]), null)],
        'map import columns' => ['profiles.import', fn ($u, $f) => app(App\Actions\Broker\Import\SaveColumnMapping::class)->handle($u, $f['batch']->code, [])],
        'fix an import row' => ['profiles.import', fn ($u, $f) => app(App\Actions\Broker\Import\FixImportRow::class)->handle($u, $f['batch']->code, 2, 'height', '163')],
        're-upload import fixes' => ['profiles.import', fn ($u, $f) => app(App\Actions\Broker\Import\ReuploadImportErrors::class)->handle($u, $f['batch']->code, importXlsx([importRow()]))],
        'attest import consent' => ['profiles.import', fn ($u, $f) => app(App\Actions\Broker\Import\AttestBatchConsent::class)->handle($u, $f['batch']->code)],
        'start an import' => ['profiles.import', fn ($u, $f) => app(App\Actions\Broker\Import\StartBatchImport::class)->handle($u, $f['batch']->code)],
        'cancel an import' => ['profiles.import', fn ($u, $f) => app(App\Actions\Broker\Import\CancelImportBatch::class)->handle($u, $f['batch']->code)],
        'submit imported profiles' => ['profiles.submit_for_review', fn ($u, $f) => app(App\Actions\Broker\Import\SubmitImportedProfiles::class)->handle($u, $f['batch']->code)],
    ];
}

it('★ every role × the import batch page and its errors.xlsx (profiles.import)', function (BureauRole $role): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $batch = App\Models\BrokerImportBatch::factory()->create(['broker_id' => $broker->id, 'uploaded_by_user_id' => $owner->id, 'status' => App\Enums\ImportBatchStatus::Ready]);
    actingAsBureau($role === BureauRole::Owner ? $owner : bureauLogin($role, broker: $broker));

    foreach (['/import/'.$batch->code, '/import/'.$batch->code.'/errors.xlsx'] as $path) {
        $response = $this->get(brokerUrl($path));
        BUREAU_MATRIX['profiles.import'][array_search($role, BureauRole::cases(), true)]
            ? $response->assertOk()
            : $response->assertForbidden()->assertHeader('X-Error-Code', BureauGate::NOT_PERMITTED);
    }
})->with(BureauRole::cases());

it('★ every role × every bureau Action (B.2): only the roles whose ceiling holds the permission get past BureauGate', function (string $action): void {
    flagOn(App\Enums\Flag::BrokerStaff);
    fakeSms();
    Illuminate\Support\Facades\Storage::fake((string) config('oppam.media.private_disk'));
    Illuminate\Support\Facades\Queue::fake();
    [$permission, $call] = bureauActions()[$action];

    foreach (BureauRole::cases() as $i => $role) {
        $owner = bureauLogin();
        $broker = staffOf($owner)->broker;
        $login = $role === BureauRole::Owner ? $owner : bureauLogin($role, broker: $broker);
        $fixture = [
            'client' => managedProfile($broker, [], staffOf($login)),   // assigned to the caller: scope never hides the permission check
            'staff' => staffOf(bureauLogin(BureauRole::DataEntry, broker: $broker)),
            'invitation' => App\Models\BrokerStaffInvitation::factory()->create(['broker_id' => $broker->id, 'expires_at' => now()->addDay()]),
            'batch' => App\Models\BrokerImportBatch::factory()->create(['broker_id' => $broker->id, 'uploaded_by_user_id' => $owner->id, 'status' => App\Enums\ImportBatchStatus::Mapping]),
        ];
        app(BureauGate::class)->forget();

        $denied = false;
        try {
            $call($login, $fixture);
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $denied = $e->getStatusCode() === 403 && ($e->getHeaders()['X-Error-Code'] ?? null) === BureauGate::NOT_PERMITTED;
        } catch (Throwable) {
            // allowed past the gate; the Action refused for its own reasons (tested elsewhere)
        }

        expect($denied)->toBe(! BUREAU_MATRIX[$permission][$i], "{$role->value} × {$action}");
    }
})->with(fn (): array => array_keys(bureauActions()));

it('every permission key of the enum is in the matrix (nothing unreviewed)', function (): void {
    expect(array_keys(BUREAU_MATRIX))->toEqualCanonicalizing(array_map(fn (BureauPermission $p): string => $p->value, BureauPermission::cases()));
});

it('R-M13-19: the Owner switches permissions off for staff — never beyond the ceiling, never for themselves', function (): void {
    $broker = App\Models\Broker::factory()->create();
    $manager = bureauLogin(BureauRole::Manager, disabled: ['profiles.import', 'profiles.export'], broker: $broker);
    $owner = bureauLogin(BureauRole::Owner, disabled: ['profiles.import', 'kyc.manage'], broker: $broker);
    // A switch-off list can't add anything: a Telecaller with an empty list still can't import.
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);

    actingAsBureau($manager);
    $this->get(brokerUrl('/import'))->assertForbidden()->assertHeader('X-Error-Code', BureauGate::NOT_PERMITTED);
    $this->get(brokerUrl('/profiles/create'))->assertOk();

    $gate = app(BureauGate::class);
    expect($gate->allows($manager, BureauPermission::ProfilesExport))->toBeFalse()
        ->and($gate->allows($owner, BureauPermission::ProfilesImport))->toBeTrue()
        ->and($gate->allows($owner, BureauPermission::KycManage))->toBeTrue()
        ->and($gate->allows($telecaller, BureauPermission::ProfilesImport))->toBeFalse();
});

it('★ Done-when — the KYC gate blocks creation routes: 403 BROKER_KYC_REQUIRED before KYC (R-M13-10 / R-A07-2)', function (string $bureau, BureauRole $role, BureauStaffStatus $status): void {
    actingAsBureau(bureauLogin($role, $bureau, status: $status));

    foreach (['/profiles/create', '/import'] as $path) {
        $this->get(brokerUrl($path))->assertForbidden()->assertHeader('X-Error-Code', BureauGate::KYC_REQUIRED)
            ->assertSee('Complete your bureau’s KYC', false);
    }
})->with([
    'applicant owner (APPLIED)' => ['applied', BureauRole::Owner, BureauStaffStatus::Invited],
    'owner, KYC under review' => ['kyc_pending', BureauRole::Owner, BureauStaffStatus::Invited],
    'manager of a bureau without KYC' => ['kyc_pending', BureauRole::Manager, BureauStaffStatus::Active],
    'data entry of a bureau without KYC' => ['applied', BureauRole::DataEntry, BureauStaffStatus::Active],
]);

it('KYC revoked: a once-verified bureau loses create / import at once (R-M13-21)', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    actingAsBureau($owner);
    $this->get(brokerUrl('/import'))->assertOk();

    $owner->brokerStaff->broker->forceFill(['kyc_status' => App\Enums\BrokerKycStatus::Revoked])->save();
    $this->get(brokerUrl('/import'))->assertForbidden()->assertHeader('X-Error-Code', BureauGate::KYC_REQUIRED);
    $this->get(brokerUrl(''))->assertOk();   // the rest of the portal still works
    // P7.7 (B.9): the batch pages stay open so a batch can be cancelled; its rows and errors.xlsx do not.
    $batch = App\Models\BrokerImportBatch::factory()->create(['broker_id' => $owner->brokerStaff->broker_id, 'uploaded_by_user_id' => $owner->id, 'status' => App\Enums\ImportBatchStatus::Ready]);
    $this->get(brokerUrl('/import/batches'))->assertOk();
    $this->get(brokerUrl('/import/'.$batch->code))->assertOk()->assertSee('can’t import profiles right now')->assertSee('Cancel batch');
    $this->get(brokerUrl('/import/'.$batch->code.'/errors.xlsx'))->assertForbidden()->assertHeader('X-Error-Code', BureauGate::KYC_REQUIRED);
});

it('B.3 before KYC: the applicant Owner reaches the dashboard and KYC, nothing that handles data', function (): void {
    actingAsBureau(applicantOwner());

    $this->get(brokerUrl(''))->assertOk()->assertSee('Next: KYC');
    $this->get(brokerUrl('/kyc'))->assertOk()->assertSee('Send KYC');
    // P7.2: materials are open before KYC (with a note); the referral list is not.
    $this->get(brokerUrl('/materials'))->assertOk()->assertSee('Referrals start counting once your bureau');
    $this->get(brokerUrl('/referrals'))->assertForbidden();
});

it('§8.1 middleware: guests log in, members get 404, a suspended / closed / rejected bureau or a deactivated login is signed out', function (): void {
    $this->get(brokerUrl(''))->assertRedirect(memberUrl('/login'));

    $this->actingAs(groom(), 'web')->get(brokerUrl(''))->assertNotFound();
    auth('web')->logout();

    foreach (['suspended', 'deactivated', 'rejected'] as $bureau) {
        actingAsBureau(bureauLogin(BureauRole::Owner, $bureau));
        $this->get(brokerUrl(''))->assertRedirect(memberUrl('/login'));
        expect(auth('web')->check())->toBeFalse();
    }

    actingAsBureau(bureauLogin(BureauRole::DataEntry, status: BureauStaffStatus::Deactivated));
    $this->get(brokerUrl(''))->assertRedirect(memberUrl('/login'));

    // A BROKER account with no bureau row is not a bureau login.
    $orphan = App\Models\User::factory()->broker()->create();
    actingAsBureau($orphan);
    $this->get(brokerUrl(''))->assertRedirect(memberUrl('/login'));
});

it('re-checked on every request: a suspension applies to the very next request (no caching)', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    actingAsBureau($owner);
    $this->get(brokerUrl(''))->assertOk();

    $owner->brokerStaff->broker->forceFill(['status' => App\Enums\BrokerStatus::Suspended])->save();
    $this->get(brokerUrl(''))->assertRedirect(memberUrl('/login'));
});

it('persistent middleware: a Livewire update after a mid-session suspension signs the login out', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    actingAsBureau($owner);
    $html = $this->get(brokerUrl('/kyc'))->assertOk()->getContent();

    $owner->brokerStaff->broker->forceFill(['status' => App\Enums\BrokerStatus::Suspended])->save();

    // One update per test: Livewire remembers per process which page's middleware it already ran.
    bureauLivewireUpdate($html)->assertRedirect(memberUrl('/login'));
    expect(auth('web')->check())->toBeFalse();
});

it('persistent middleware: a Livewire update from a device that is no longer trusted gets 403', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    actingAsBureau($owner);
    $html = $this->get(brokerUrl('/kyc'))->assertOk()->getContent();

    App\Models\TrustedDevice::query()->where('user_id', $owner->id)->delete();

    bureauLivewireUpdate($html)->assertForbidden();
});

it('persistent middleware: the same Livewire update from a trusted device of an active bureau goes through', function (): void {
    actingAsBureau(bureauLogin(BureauRole::Owner));

    bureauLivewireUpdate($this->get(brokerUrl('/kyc'))->assertOk()->getContent())->assertOk();
});

it('§8.1: an Owner or Manager on an unknown device is sent to the device OTP; other roles are not', function (BureauRole $role, bool $challenged): void {
    $this->actingAs(bureauLogin($role), 'web');

    $response = $this->get(brokerUrl(''));

    $challenged ? $response->assertRedirect(brokerUrl('/verify-device')) : $response->assertOk();
})->with([
    'owner' => [BureauRole::Owner, true],
    'manager' => [BureauRole::Manager, true],
    'data entry' => [BureauRole::DataEntry, false],
    'telecaller' => [BureauRole::Telecaller, false],
]);
