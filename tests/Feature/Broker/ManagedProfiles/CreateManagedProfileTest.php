<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\CreateManagedProfile;
use App\Domain\Broker\BureauGate;
use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\BureauRole;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Models\AuditLog;
use App\Models\BrokerReferral;
use App\Models\Profile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.3 — CreateManagedProfile (§11A B.6, B.17): the first "Save draft" of the bureau form.
| R-M13-10 KYC gate, R-M13-11 consent, R-M13-23 the individual staff user, the client mobile as the
| future claim key, the M02 basics rules, attribution, assigned-only creators, mass assignment.
*/

beforeEach(function (): void {
    seedMasters();
});

function createManaged(App\Models\User $login, ?App\Data\Broker\ManagedProfileData $data = null, array $basic = []): Profile
{
    return app(CreateManagedProfile::class)->handle($login, $data ?? managedData(), basicData($basic));
}

it('B.6: creates a DRAFT, BROKER_MANAGED by the caller’s bureau, with the basics saved by the M02 Action', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $staff = staffOf($login);

    $profile = createManaged($login);

    expect($profile->status)->toBe(ProfileStatus::Draft)
        ->and($profile->user_id)->toBeNull()
        ->and($profile->owner_type)->toBe(ProfileOwnerType::BrokerManaged)
        ->and($profile->managed_by_broker_id)->toBe($staff->broker_id)
        ->and($profile->client_phone)->toBe('+919876512345')
        ->and($profile->created_by_user_id)->toBe($login->id)
        ->and($profile->first_name)->toBe('Anjali')
        ->and($profile->last_name)->toBe('Nair')
        ->and($profile->height_cm)->toBe(163)
        ->and($profile->completeness)->toBeGreaterThan(0)
        ->and($profile->assigned_staff_id)->toBeNull()
        ->and($profile->code)->toStartWith('OPM');

    expect(AuditLog::query()->where('action', 'broker.profile_created')->where('subject_label', $profile->code)->exists())->toBeTrue();
});

it('R-M13-11 / R-M13-23: the consent stamp records the individual staff user and the time', function (): void {
    $this->freezeTime();
    $login = bureauLogin(BureauRole::Manager);

    $profile = createManaged($login);

    expect($profile->broker_consent_confirmed_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($profile->consent_confirmed_by_user_id)->toBe($login->id);
});

it('R-M13-11: without consent → 422 and nothing is written', function (): void {
    $login = bureauLogin();

    expect(fn () => createManaged($login, managedData(consent: false)))
        ->toThrow(ValidationException::class);

    expect(Profile::query()->count())->toBe(0)->and(BrokerReferral::query()->count())->toBe(0);
});

it('R-M13-10: a bureau without verified KYC gets 403 BROKER_KYC_REQUIRED, nothing written', function (string $bureau, string $code): void {
    $login = bureauLogin(BureauRole::Owner, $bureau);

    try {
        createManaged($login);
        $this->fail('expected a 403');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403)->and($e->getHeaders()['X-Error-Code'])->toBe($code);
    }

    expect(Profile::query()->count())->toBe(0);
})->with([
    'KYC pending' => ['kyc_pending', BureauGate::KYC_REQUIRED],
    'applied' => ['applied', BureauGate::KYC_REQUIRED],
]);

it('R-M13-21 / R-A07-8: a suspended or deactivated bureau creates nothing', function (string $bureau): void {
    $login = bureauLogin(BureauRole::Owner, $bureau);

    expect(fn () => createManaged($login))->toThrow(HttpException::class);
    expect(Profile::query()->count())->toBe(0);
})->with(['suspended', 'deactivated']);

it('B.2: a Telecaller may not create; an Owner switch-off removes it from staff', function (): void {
    expect(fn () => createManaged(bureauLogin(BureauRole::Telecaller)))->toThrow(HttpException::class);
    expect(fn () => createManaged(bureauLogin(BureauRole::DataEntry, disabled: ['profiles.create'])))->toThrow(HttpException::class);
    expect(fn () => createManaged(bureauLogin(BureauRole::DataEntry, disabled: ['profiles.edit'])))->toThrow(HttpException::class);
    expect(Profile::query()->count())->toBe(0);
});

it('a member account can’t use the bureau Action', function (): void {
    expect(fn () => createManaged(bride()))->toThrow(HttpException::class);
    expect(Profile::query()->whereNull('user_id')->count())->toBe(0);
});

it('B.17: the client mobile is required, valid and E.164-normalised', function (): void {
    $login = bureauLogin();

    expect(fn () => createManaged($login, managedData('')))->toThrow(ValidationException::class);
    expect(fn () => createManaged($login, managedData('12345')))->toThrow(ValidationException::class);

    expect(createManaged($login, managedData(' 98765-43210 '))->client_phone)->toBe('+919876543210');
});

it('B.17: a mobile already on Oppam (a member, a deleted member, another managed profile, a ban) is refused, nothing written', function (string $case): void {
    $login = bureauLogin();

    match ($case) {
        'member' => memberWithPhone('+919876512345'),
        'deleted member' => memberWithPhone('+919876512345')->delete(),
        'other bureau profile' => managedProfile(App\Models\Broker::factory()->create(), ['client_phone' => '+919876512345']),
        'banned' => app(BanList::class)->add(BannedIdentifierType::Phone, '+919876512345', null, null, null),
    };
    $before = Profile::withTrashed()->count();

    try {
        createManaged($login);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('clientPhone')
            ->and($e->errors()['clientPhone'][0])->toBe(CreateManagedProfile::phoneTakenMessage());
    }

    expect(Profile::withTrashed()->count())->toBe($before);
})->with(['member', 'deleted member', 'other bureau profile', 'banned']);

it('B.17: the basics follow the M02 rules (age 18 F / 21 M, caste belongs to religion) — 422 before any write', function (array $basic, string $field): void {
    $login = bureauLogin();

    try {
        createManaged($login, basic: $basic);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(Profile::query()->count())->toBe(0);
})->with([
    'bride under 18' => [fn () => ['dob' => now(config('oppam.display_timezone'))->subYears(17)->format('Y-m-d')], 'dob'],
    'groom under 21' => [fn () => ['gender' => 'MALE', 'dob' => now(config('oppam.display_timezone'))->subYears(20)->format('Y-m-d')], 'dob'],
    'caste of another religion' => [fn () => ['caste_id' => (int) App\Models\Masters\Caste::query()
        ->where('religion_id', masterId(App\Models\Masters\Religion::class, 'CHRISTIAN'))->value('id')], 'caste_id'],
    'no height' => [['height_cm' => null], 'height_cm'],
    'no religion' => [['religion_id' => null, 'caste_id' => null], 'religion_id'],
]);

it('B.10: a managed profile is attributed to its bureau with a PENDING MANAGED ledger row', function (): void {
    $login = bureauLogin();
    $profile = createManaged($login);

    $row = BrokerReferral::query()->where('profile_id', $profile->id)->firstOrFail();

    expect($row->broker_id)->toBe(staffOf($login)->broker_id)
        ->and($row->source)->toBe(ReferralSource::Managed)
        ->and($row->status)->toBe(ReferralStatus::Pending)
        ->and($profile->broker_id)->toBe($row->broker_id)
        ->and($profile->broker_verified)->toBeTrue();
});

it('R-M13-20 (decisions 2026-10-25): an assigned-only creator is assigned to the profile they create', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $staff = staffOf($login);
    $staff->forceFill(['profile_scope' => ProfileScope::Assigned])->save();

    expect(createManaged($login)->assigned_staff_id)->toBe($staff->id);
});

it('two creates with one mobile: the second is refused (unique claim key)', function (): void {
    createManaged(bureauLogin());

    expect(fn () => createManaged(bureauLogin()))->toThrow(ValidationException::class);
    expect(Profile::query()->count())->toBe(1);
});

it('is capped per login per hour', function (): void {
    config(['oppam.broker.managed_profiles_per_hour' => 2]);
    $login = bureauLogin();

    createManaged($login, managedData('9876500001'));
    createManaged($login, managedData('9876500002'));

    expect(fn () => createManaged($login, managedData('9876500003')))->toThrow(ValidationException::class);
    expect(Profile::query()->count())->toBe(2);
});
