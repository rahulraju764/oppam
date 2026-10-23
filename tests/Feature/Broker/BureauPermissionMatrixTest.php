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
});

it('B.3 before KYC: the applicant Owner reaches the dashboard and KYC, nothing that handles data', function (): void {
    actingAsBureau(applicantOwner());

    $this->get(brokerUrl(''))->assertOk()->assertSee('Next: KYC');
    $this->get(brokerUrl('/kyc'))->assertOk()->assertSee('Send KYC');
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
