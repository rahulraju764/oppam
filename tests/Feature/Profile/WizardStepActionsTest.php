<?php

declare(strict_types=1);

use App\Actions\Profile\SaveBasicDetails;
use App\Actions\Profile\SaveCareerDetails;
use App\Actions\Profile\SaveFamilyDetails;
use App\Domain\Profile\WizardProgress;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Enums\WizardStep;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Religion;
use App\Models\Masters\State;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
| P1.2 — wizard steps 1–3 (M02): SaveBasicDetails / SaveCareerDetails / SaveFamilyDetails.
| Rules from ProfileRules, authorization via ProfilePolicy::editWizard, partial autosave.
*/

beforeEach(function (): void {
    seedMasters();
});

function saveBasic(User $user, array $overrides = [], bool $partial = false): void
{
    app(SaveBasicDetails::class)->handle($user, $user->profile()->firstOrFail(), basicData($overrides), $partial);
}

function validationErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

// ---- Step 1 ---------------------------------------------------------------------------------

it('saves step 1 into the profile and its horoscope row', function (): void {
    $user = draftMember();

    saveBasic($user, ['chovva_dosham' => 'NO', 'sub_caste' => '  Menon  ']);

    $profile = $user->profile()->firstOrFail();

    expect($profile->last_name)->toBe('Nair')
        ->and($profile->height_cm)->toBe(163)
        ->and($profile->marital_status)->toBe(MaritalStatus::NeverMarried)
        ->and($profile->sub_caste)->toBe('Menon')
        ->and($profile->mother_tongue_id)->toBe(masterId(MotherTongue::class, 'MALAYALAM'))
        ->and($profile->horoscopeDetail?->chovva_dosham?->value)->toBe('NO')
        ->and($profile->status)->toBe(ProfileStatus::Draft)
        ->and((new WizardProgress($profile))->isComplete(WizardStep::Basic))->toBeTrue();
});

it('M02 age rule: a bride under 18 and a groom under 21 cannot pass step 1', function (Gender $gender, int $years, bool $ok): void {
    $user = draftMember($gender);
    $dob = now(config('oppam.display_timezone'))->subYears($years)->addDay()->format('Y-m-d');   // one day short of $years

    $errors = validationErrors(fn () => saveBasic($user, ['gender' => $gender->value, 'dob' => $dob]));

    expect(in_array('dob', $errors, true))->toBe(! $ok);
})->with([
    'bride just under 18' => [Gender::Female, 18, false],
    'bride 19' => [Gender::Female, 19, true],
    'groom just under 21' => [Gender::Male, 21, false],
    'groom 22' => [Gender::Male, 22, true],
]);

it('refuses a caste that does not belong to the religion (tampered request)', function (): void {
    $user = draftMember();
    $christianCaste = (int) Caste::query()->where('religion_id', masterId(Religion::class, 'CHRISTIAN'))->value('id');

    expect(validationErrors(fn () => saveBasic($user, ['caste_id' => $christianCaste])))->toContain('caste_id');
});

it('refuses inactive or unknown master values', function (): void {
    $user = draftMember();
    $tongue = MotherTongue::query()->where('code', 'TAMIL')->firstOrFail();
    $tongue->forceFill(['is_active' => false])->save();

    expect(validationErrors(fn () => saveBasic($user, ['mother_tongue_id' => $tongue->id])))->toContain('mother_tongue_id')
        ->and(validationErrors(fn () => saveBasic($user, ['religion_id' => 999999])))->toContain('religion_id');
});

it('stores 0 children for a never-married member whatever is sent', function (): void {
    $user = draftMember();

    saveBasic($user, ['children_count' => 3]);

    expect($user->profile()->firstOrFail()->children_count)->toBe(0);
});

it('rejects invalid input on a full save and writes nothing', function (array $overrides, string $field): void {
    $user = draftMember();

    expect(validationErrors(fn () => saveBasic($user, $overrides)))->toContain($field)
        ->and($user->profile()->firstOrFail()->last_name)->toBeNull();
})->with([
    'missing last name' => [['last_name' => null], 'last_name'],
    'height out of range' => [['height_cm' => 250], 'height_cm'],
    'digits in name' => [['last_name' => 'N4ir'], 'last_name'],
    'unknown marital status' => [['marital_status' => 'COMPLICATED'], 'marital_status'],
    'bad date' => [['dob' => '31-02-1998'], 'dob'],
]);

it('autosave keeps a half-filled step on a DRAFT profile', function (): void {
    $user = draftMember();

    saveBasic($user, ['last_name' => null, 'dob' => null, 'height_cm' => 170, 'religion_id' => null, 'caste_id' => null], partial: true);

    $profile = $user->profile()->firstOrFail();

    expect($profile->height_cm)->toBe(170)
        ->and($profile->dob)->toBeNull()
        ->and((new WizardProgress($profile))->isComplete(WizardStep::Basic))->toBeFalse();
});

it('autosave never saves invalid values', function (): void {
    $user = draftMember();

    expect(validationErrors(fn () => saveBasic($user, ['height_cm' => 999, 'dob' => null], partial: true)))->toContain('height_cm');
});

it('a REJECTED profile must stay complete: autosave is treated as a full save', function (): void {
    $user = memberThroughStep(1);
    $user->profile->forceFill(['status' => ProfileStatus::Rejected])->save();

    expect(validationErrors(fn () => saveBasic($user->refresh(), ['last_name' => null], partial: true)))->toContain('last_name');
});

// ---- Authorization (security matrix) --------------------------------------------------------

it('refuses to save another member\'s profile', function (): void {
    $owner = draftMember();
    $other = draftMember();

    expect(fn () => app(SaveBasicDetails::class)->handle($other, $owner->profile, basicData()))
        ->toThrow(AuthorizationException::class);
});

it('refuses wizard edits of a pending or suspended profile, and by a suspended member (a live profile may edit, R-M02-4)', function (Closure $arrange): void {
    $user = memberThroughStep(1);
    $arrange($user);

    expect(fn () => saveBasic($user->refresh()))->toThrow(AuthorizationException::class);
})->with([
    'PENDING_REVIEW profile' => fn (User $u) => $u->profile->forceFill(['status' => ProfileStatus::PendingReview])->save(),
    'SUSPENDED profile' => fn (User $u) => $u->profile->forceFill(['status' => ProfileStatus::Suspended])->save(),
    'suspended member' => fn (User $u) => $u->forceFill(['status' => UserStatus::Suspended])->save(),
]);

// ---- Step 2 ---------------------------------------------------------------------------------

it('saves step 2 and uses the native (permanent) district for search', function (): void {
    $user = memberThroughStep(1);
    $thrissur = keralaDistrictId('THRISSUR');

    app(SaveCareerDetails::class)->handle($user, $user->profile, careerData(['permanent_district_id' => $thrissur]));

    $profile = $user->profile()->firstOrFail();

    expect($profile->educationCareer?->employer_name)->toBe('Infopark')
        ->and($profile->district_id)->toBe($thrissur)
        ->and((new WizardProgress($profile))->isComplete(WizardStep::Career))->toBeTrue();
});

it('requires state and district for India, and they must chain to the country and state', function (): void {
    $user = memberThroughStep(1);
    $tamilNadu = masterId(State::class, 'TN');
    $wrongDistrict = (int) District::query()->where('state_id', '!=', masterId(State::class, 'KL'))->value('id')
        ?: 999999;

    expect(validationErrors(fn () => app(SaveCareerDetails::class)->handle($user, $user->profile, careerData(['current_state_id' => null, 'current_district_id' => null]))))
        ->toContain('current_state_id')->toContain('current_district_id')
        ->and(validationErrors(fn () => app(SaveCareerDetails::class)->handle($user, $user->profile, careerData(['current_district_id' => $wrongDistrict]))))
        ->toContain('current_district_id')
        ->and(validationErrors(fn () => app(SaveCareerDetails::class)->handle($user, $user->profile, careerData(['current_state_id' => $tamilNadu]))))
        ->toContain('current_district_id');   // Ernakulam is not in Tamil Nadu
});

it('lets an NRI living abroad skip state and district for the current location', function (): void {
    $user = memberThroughStep(1);
    $uae = masterId(Country::class, 'AE');

    app(SaveCareerDetails::class)->handle($user, $user->profile, careerData([
        'current_country_id' => $uae, 'current_state_id' => null, 'current_district_id' => null,
        'current_city' => 'Dubai', 'citizenship' => 'Indian', 'visa_status' => 'Employment visa',
    ]));

    expect($user->profile->educationCareer()->firstOrFail()->visa_status)->toBe('Employment visa')
        ->and($user->profile()->firstOrFail()->district_id)->toBe(keralaDistrictId());   // native district
});

// ---- Step 3 ---------------------------------------------------------------------------------

it('saves step 3 with sibling counts defaulting to 0', function (): void {
    $user = memberThroughStep(2);

    app(SaveFamilyDetails::class)->handle($user, $user->profile, familyData(['brothers_married' => null]));

    $family = $user->profile->familyDetail()->firstOrFail();

    expect($family->father_name)->toBe('Gopalan Nair')
        ->and($family->brothers_married)->toBe(0)
        ->and($family->sisters_unmarried)->toBe(1)
        ->and((new WizardProgress($user->profile()->firstOrFail()))->isComplete(WizardStep::Family))->toBeTrue();
});

it('refuses an option from the wrong master group', function (): void {
    $user = memberThroughStep(2);

    expect(validationErrors(fn () => app(SaveFamilyDetails::class)->handle($user, $user->profile, familyData([
        'family_status_option_id' => optionId('family_type', 'JOINT'),
    ]))))->toContain('family_status_option_id');
});

// ---- Step order -------------------------------------------------------------------------------

it('opens steps only in order', function (): void {
    $profile = draftMember()->profile;
    $progress = new WizardProgress($profile);

    expect($progress->firstIncomplete())->toBe(WizardStep::Basic)
        ->and($progress->canOpen(WizardStep::Career))->toBeFalse();

    $profile = memberThroughStep(2)->profile;
    $progress = new WizardProgress($profile);

    expect($progress->firstIncomplete())->toBe(WizardStep::Family)
        ->and($progress->canOpen(WizardStep::Family))->toBeTrue()
        ->and($progress->canOpen(WizardStep::Preferences))->toBeFalse()
        ->and($progress->percent())->toBe(33);
});
