<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\ExportManagedProfile;
use App\Domain\Broker\BiodataBuilder;
use App\Domain\Broker\BureauGate;
use App\Enums\BrokerExportType;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\PhotoStatus;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileScope;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerProfileExport;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| ★ ExportAuthorizationTest (PRD §11A B.7, B.18, B.22) — P7.4. A bureau exports (biodata PDF,
| photo package) only the profiles it manages: R-M13-12 (query-time ownership, no exceptions),
| R-M13-13 (a broker_profile_exports row on EVERY attempt, allowed or not), R-M13-21 / R-A07-8
| (inactive / KYC-revoked bureau), R-M13-23 (the individual staff user), R-A07-7 (append-only),
| the role matrix (B.2 `profiles.export`: Owner, Manager) and assigned-only scope (R-M13-20).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function exportUrl(Profile $profile, string $what = 'biodata'): string
{
    return brokerUrl('/profiles/'.$profile->code.'/export/'.$what);
}

/** A managed client photo in the given moderation state (uploaded through the bureau path). */
function managedPhoto(User $login, Profile $profile, PhotoStatus $status, string $name = 'photo.jpg'): Media
{
    fakeMediaDisks();
    Bus::fake([PerformConversionsJob::class]);
    $media = app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle($login, $profile->refresh(), UploadedFile::fake()->image($name, 600, 800));
    $media->forceFill(['moderation_status' => $status])->save();

    return $media;
}

/** @return list<BrokerProfileExport> */
function exportRows(): array
{
    return array_values(BrokerProfileExport::query()->orderBy('created_at')->orderBy('id')->get()->all());
}

// ---- Allowed --------------------------------------------------------------------------------------

it('★ R-M13-12 / R-M13-23: the Owner and a Manager download the biodata PDF; the row names the bureau, the staff user and the IP', function (BureauRole $role): void {
    $login = bureauLogin($role);
    $profile = managedProfile(staffOf($login)->broker);
    actingAsBureau($login);

    $response = $this->get(exportUrl($profile));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$profile->code.'-biodata.pdf"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(str_starts_with((string) $response->getContent(), '%PDF-'))->toBeTrue();

    $row = BrokerProfileExport::query()->sole();
    expect($row->allowed)->toBeTrue()
        ->and($row->broker_id)->toBe(staffOf($login)->broker_id)
        ->and($row->user_id)->toBe($login->id)
        ->and($row->profile_id)->toBe($profile->id)
        ->and($row->export_type)->toBe(BrokerExportType::BiodataPdf)
        ->and($row->ip)->toBe('127.0.0.1')
        ->and(AuditLog::query()->where('action', 'broker.profile_exported')->where('subject_label', $profile->code)->exists())->toBeTrue();
})->with([BureauRole::Owner, BureauRole::Manager]);

it('B.7 / B.18: the biodata carries the bureau name, code and phone in the footer and the "Prepared by" watermark; no contact details', function (): void {
    $login = bureauLogin();
    $broker = staffOf($login)->broker;
    $profile = managedClientReadyToSubmit($login);

    $bio = app(BiodataBuilder::class)->biodata($profile->refresh(), $broker);
    $html = view('pdf.biodata', ['bio' => $bio, 'site' => 'Oppam Matrimony', 'fonts' => [
        'regular' => 'r.ttf', 'bold' => 'b.ttf', 'mlRegular' => 'mr.ttf', 'mlBold' => 'mb.ttf',
    ]])->render();

    expect($html)->toContain('Prepared by '.e($broker->displayName()).', Oppam Matrimony')
        ->and($html)->toContain($broker->code)
        ->and($html)->toContain($broker->phone)
        ->and($html)->toContain(e($profile->fullName()))
        ->and($html)->toContain('font-family: "Noto Sans Malayalam"');

    // Never the contact step (phones, email, address) — the same data the owner sees on their own profile page.
    expect($profile->contactDetail)->not->toBeNull()
        ->and($html)->not->toContain('family@example.com')
        ->and($html)->not->toContain(e('6 pm – 9 pm'))
        ->and($html)->not->toContain((string) $profile->client_phone)
        ->and($bio->photo)->toBeNull();   // its only photo is still PENDING moderation
});

it('B.7: the photo package holds the APPROVED originals only, named by code', function (): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);
    managedPhoto($login, $profile, PhotoStatus::Approved, 'a.jpg');
    managedPhoto($login, $profile, PhotoStatus::Pending, 'b.jpg');
    managedPhoto($login, $profile, PhotoStatus::Rejected, 'c.jpg');
    managedPhoto($login, $profile, PhotoStatus::Approved, 'd.jpg');
    actingAsBureau($login);

    $response = $this->get(exportUrl($profile, 'photos'))->assertOk()->assertHeader('Content-Type', 'application/zip');

    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->toBe([$profile->code.'-1.jpg', $profile->code.'-2.jpg'])
        ->and(BrokerProfileExport::query()->sole()->export_type)->toBe(BrokerExportType::PhotoPackage);
});

it('B.7: without an approved photo the package is refused — and the attempt is still logged', function (): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);
    managedPhoto($login, $profile, PhotoStatus::Pending);
    actingAsBureau($login);

    $this->get(exportUrl($profile, 'photos'))->assertStatus(409)->assertHeader('X-Error-Code', ExportManagedProfile::NO_PHOTOS);

    expect(BrokerProfileExport::query()->sole()->allowed)->toBeFalse();
});

// ---- Refused: 403 + a row with allowed = false -----------------------------------------------------

it('★ R-M13-12 / R-M13-13: a profile the bureau does not manage → 403 PROFILE_NOT_MANAGED_BY_BROKER, logged', function (string $case): void {
    $login = bureauLogin();
    $own = staffOf($login)->broker;

    $profile = match ($case) {
        'another bureau’s client' => managedProfile(Broker::factory()->create()),
        'a member referred by us (not managed)' => referredMember($own)->profile,
        'any member' => bride()->profile,
        'our client, claimed' => managedProfile($own, ['claimed_at' => now(), 'owner_type' => ProfileOwnerType::Self]),
        'our client, deleted' => tap(managedProfile($own))->delete(),
    };
    actingAsBureau($login);

    foreach (['biodata', 'photos'] as $what) {
        $this->get(exportUrl($profile, $what))
            ->assertForbidden()
            ->assertHeader('X-Error-Code', ExportManagedProfile::NOT_MANAGED);
    }

    $rows = exportRows();
    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->every(fn (BrokerProfileExport $r): bool => ! $r->allowed && $r->user_id === $login->id
            && $r->broker_id === $own->id && $r->profile_id === $profile->id))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'broker.profile_exported')->exists())->toBeFalse();
})->with(['another bureau’s client', 'a member referred by us (not managed)', 'any member', 'our client, claimed', 'our client, deleted']);

it('★ B.2: Data Entry, Telecaller and a Manager with the Owner’s switch-off → 403 BUREAU_PERMISSION, logged', function (BureauRole $role, array $disabled): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $login = bureauLogin($role, broker: $broker, disabled: $disabled);
    $profile = managedProfile($broker);
    actingAsBureau($login);

    $this->get(exportUrl($profile))->assertForbidden()->assertHeader('X-Error-Code', BureauGate::NOT_PERMITTED);

    $row = BrokerProfileExport::query()->sole();
    expect($row->allowed)->toBeFalse()->and($row->user_id)->toBe($login->id);
})->with([
    'data entry' => [BureauRole::DataEntry, []],
    'telecaller' => [BureauRole::Telecaller, []],
    'manager, switched off' => [BureauRole::Manager, ['profiles.export']],
]);

it('★ R-M13-21 / B.19: KYC revoked later → 403 BROKER_KYC_REQUIRED for everyone, logged; existing profiles untouched', function (): void {
    $login = bureauLogin();
    $broker = staffOf($login)->broker;
    $profile = managedProfile($broker);
    $broker->forceFill(['kyc_status' => BrokerKycStatus::Rejected])->save();
    actingAsBureau($login);

    $this->get(exportUrl($profile))->assertForbidden()->assertHeader('X-Error-Code', BureauGate::KYC_REQUIRED);

    expect(BrokerProfileExport::query()->sole()->allowed)->toBeFalse()
        ->and($profile->refresh()->managed_by_broker_id)->toBe($broker->id);
});

it('★ R-A07-8: a suspended or deactivated bureau can’t reach the export (portal closed)', function (BrokerStatus $status): void {
    $login = bureauLogin();
    $broker = staffOf($login)->broker;
    $profile = managedProfile($broker);
    $broker->forceFill(['status' => $status])->save();
    actingAsBureau($login);

    $this->get(exportUrl($profile))->assertRedirect();   // broker.active signs the login out of a closed portal (P7.1)

    // Called directly (any other way in), the Action refuses and still logs.
    expect(fn () => app(ExportManagedProfile::class)->handle($login, $profile->code, BrokerExportType::BiodataPdf, null))
        ->toThrow(HttpException::class);
    expect(BrokerProfileExport::query()->where('allowed', false)->count())->toBeGreaterThanOrEqual(1)
        ->and(BrokerProfileExport::query()->where('allowed', true)->exists())->toBeFalse();
})->with([BrokerStatus::Suspended, BrokerStatus::Deactivated]);

it('★ R-M13-20: assigned-only staff export only their own clients — others → 403, logged', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $manager = bureauLogin(BureauRole::Manager, broker: $broker);
    staffOf($manager)->forceFill(['profile_scope' => ProfileScope::Assigned])->save();
    $mine = managedProfile($broker, [], staffOf($manager));
    $theirs = managedProfile($broker);
    actingAsBureau($manager);

    $this->get(exportUrl($mine))->assertOk();
    $this->get(exportUrl($theirs))->assertForbidden()->assertHeader('X-Error-Code', ExportManagedProfile::NOT_MANAGED);

    expect(collect(exportRows())->map(fn (BrokerProfileExport $r): array => [$r->profile_id, $r->allowed])->all())
        ->toBe([[$mine->id, true], [$theirs->id, false]]);
});

// ---- Other cases -----------------------------------------------------------------------------------

it('an unknown code is a 404 and writes nothing (the row needs a real profile)', function (): void {
    actingAsBureau(bureauLogin());

    $this->get(brokerUrl('/profiles/OPM99999999/export/biodata'))->assertNotFound();
    $this->get(brokerUrl('/profiles/not-a-code/export/biodata'))->assertNotFound();

    expect(BrokerProfileExport::query()->count())->toBe(0);
});

it('a guest goes to the login; a member account never reaches the export', function (): void {
    $profile = managedProfile(Broker::factory()->create());

    $this->get(exportUrl($profile))->assertRedirect();

    $this->actingAs(groom(), 'web')->get(exportUrl($profile))->assertNotFound();   // role:BROKER — the portal doesn't exist for members
    expect(BrokerProfileExport::query()->count())->toBe(0);
});

it('is capped per login per hour — the refused attempt is logged too', function (): void {
    config(['oppam.broker.exports_per_hour' => 2]);
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);
    actingAsBureau($login);

    $this->get(exportUrl($profile))->assertOk();
    $this->get(exportUrl($profile))->assertOk();
    $this->get(exportUrl($profile))->assertStatus(429)->assertHeader('X-Error-Code', ExportManagedProfile::TOO_MANY);

    expect(collect(exportRows())->pluck('allowed')->all())->toBe([true, true, false]);
});

it('R-A07-7: export rows are append-only', function (): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);
    actingAsBureau($login);
    $this->get(exportUrl($profile))->assertOk();
    $row = BrokerProfileExport::query()->sole();

    expect(fn () => $row->forceFill(['allowed' => false])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});
