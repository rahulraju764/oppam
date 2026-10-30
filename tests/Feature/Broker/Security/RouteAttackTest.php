<?php

declare(strict_types=1);

use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ImportBatchStatus;
use App\Models\BrokerImportBatch;
use App\Models\BrokerProfileExport;
use App\Models\User;
use Database\Seeders\PlansSeeder;

/*
| P7.9 — broker security sweep, typed URLs (PRD §11A B.18, B.22). Every bureau route, attacked as a
| guest, a member login, another bureau's Owner holding the victim's codes, a stale session of a
| deactivated staff login, and the Owner of a suspended bureau. BureauPermissionMatrixTest already
| covers every role of the bureau itself; IdorScopingTest / ExportAuthorizationTest the per-profile
| rules in depth. This file adds the outside attackers on every route at once. Each route must be
| listed here — SurfaceInventoryTest fails when one isn't.
*/

const SWEEP_PORTAL_ROUTES = [
    // path (placeholders filled from the victim bureau) => what another bureau's Owner gets
    '/' => 'own', '/activity' => 'own', '/earnings' => 'own', '/earnings/statements/{run}' => 404,
    '/import' => 'own', '/import/batches' => 'own', '/import/template.csv' => 'own', '/import/template.xlsx' => 'own',
    '/import/{batch}' => 404, '/import/{batch}/errors.xlsx' => 404,
    '/kyc' => 'own', '/materials' => 'own', '/materials/poster.pdf' => 'own', '/materials/qr.png' => 'own', '/materials/qr.svg' => 'own',
    '/profiles' => 'own', '/profiles/create' => 'own',
    '/profiles/{code}' => 404, '/profiles/{code}/edit' => 404, '/profiles/{code}/inbox' => 404, '/profiles/{code}/notes' => 404,
    '/profiles/{code}/export/biodata' => 403, '/profiles/{code}/export/photos' => 403,
    '/referrals' => 'own', '/staff' => 'own', '/staff/{staff}' => 404, '/verify-device' => 'own',
];

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    flagOn(App\Enums\Flag::BrokerBulkImport);
    Illuminate\Support\Facades\Notification::fake();

    $this->victimOwner = bureauLogin();
    $this->victim = staffOf($this->victimOwner)->broker;
    $this->victimStaff = bureauLogin(BureauRole::Manager, broker: $this->victim);
    $this->fixtures = [
        '{code}' => managedProfile($this->victim)->code,
        '{batch}' => BrokerImportBatch::factory()->create(['broker_id' => $this->victim->id, 'uploaded_by_user_id' => $this->victimOwner->id, 'status' => ImportBatchStatus::Ready])->code,
        '{staff}' => staffOf($this->victimStaff)->code,
        '{run}' => (function () {
            earnedCommission($this->victim, 60_000);

            return paidRun(adminWithRole('finance'))->code;
        })(),
    ];
});

function sweepUrl(string $path, array $fixtures): string
{
    return brokerUrl(rtrim(strtr($path, $fixtures), '/'));
}

it('★ B.18 guest: every portal route sends a guest to sign in', function (string $path): void {
    $this->get(sweepUrl($path, $this->fixtures))->assertRedirect();
    expect(auth('web')->check())->toBeFalse();
})->with(array_keys(SWEEP_PORTAL_ROUTES));

it('★ B.18 member login: a member account never reaches a portal route (role:BROKER)', function (string $path): void {
    $this->actingAs(User::factory()->create(), 'web');

    expect($this->get(sweepUrl($path, $this->fixtures))->status())->toBeIn([403, 404]);
})->with(array_keys(SWEEP_PORTAL_ROUTES));

it('★ R-M13-6 / R-M13-18 another bureau’s Owner with the victim’s codes: 404 (exports 403 + logged), its own pages otherwise', function (string $path): void {
    $attacker = actingAsBureau(bureauLogin());
    $exportsBefore = BrokerProfileExport::query()->count();

    $response = $this->get(sweepUrl($path, $this->fixtures));
    $expected = SWEEP_PORTAL_ROUTES[$path];

    if ($expected === 'own') {
        expect($response->status())->toBeIn([200, 302])
            ->and((string) $response->getContent())->not->toContain($this->fixtures['{code}']);   // never the victim's data
    } else {
        $response->assertStatus($expected);
    }
    if ($expected === 403) {
        expect(BrokerProfileExport::query()->count())->toBe($exportsBefore + 1)
            ->and(BrokerProfileExport::query()->latest('created_at')->first()?->allowed)->toBeFalse()
            ->and(BrokerProfileExport::query()->latest('created_at')->first()?->user_id)->toBe($attacker->id);
    }
})->with(array_keys(SWEEP_PORTAL_ROUTES));

it('★ B.18 / B.19 stale session: a staff login deactivated after signing in is signed out on its next request, on every route', function (string $path): void {
    actingAsBureau($this->victimStaff);
    staffOf($this->victimStaff)->forceFill(['status' => BureauStaffStatus::Deactivated])->save();

    $response = $this->get(sweepUrl($path, $this->fixtures));

    expect($response->status())->toBeIn([302, 403])
        ->and(in_array($response->status(), [302], true) ? (string) $response->headers->get('Location') : 'login')->toContain('login');
})->with(array_keys(SWEEP_PORTAL_ROUTES));

it('★ R-A07-8 suspended / deactivated bureau: the Owner reaches no portal route', function (string $path, BrokerStatus $status): void {
    actingAsBureau($this->victimOwner);
    $this->victim->forceFill(['status' => $status])->save();

    expect($this->get(sweepUrl($path, $this->fixtures))->status())->toBeIn([302, 403]);
})->with(array_keys(SWEEP_PORTAL_ROUTES))->with([BrokerStatus::Suspended, BrokerStatus::Deactivated]);

it('B.18 public bureau routes: the application and the invitation link are guest-only and throttled; the code check says name + district only', function (): void {
    $this->get(brokerUrl('/validate?code='.$this->victim->code))->assertOk()
        ->assertJsonStructure(['valid', 'name', 'district'])
        ->assertJsonMissingPath('phone')->assertJsonMissingPath('email')->assertJsonMissingPath('id');

    actingAsBureau($this->victimOwner);
    $this->get(brokerUrl('/apply'))->assertRedirect();                                         // guest only
    $this->get(brokerUrl('/staff/invite/'.str_repeat('a', 64)))->assertRedirect();
});
