<?php

declare(strict_types=1);

use App\Data\Profile\BasicDetailsData;
use App\Data\Profile\CareerDetailsData;
use App\Data\Profile\FamilyDetailsData;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\ProfileStatus;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Religion;
use App\Models\Masters\State;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\MastersSeeder;

/*
| Profile / wizard test helpers (P1.2+). Loaded from tests/Pest.php. Master ids are looked up by
| their immutable codes from database/seeders/data/masters.php.
*/

function seedMasters(): void
{
    test()->seed(MastersSeeder::class);
}

/** @param class-string<App\Models\Masters\MasterRecord> $model */
function masterId(string $model, string $code, array $where = []): int
{
    return (int) $model::query()->where('code', $code)->where($where)->value('id');
}

function optionId(string $group, string $code): int
{
    return masterId(MasterOption::class, $code, ['group' => $group]);
}

function keralaDistrictId(string $code = 'ERNAKULAM'): int
{
    $kerala = masterId(State::class, 'KL');

    return (int) District::query()->where('state_id', $kerala)->where('code', $code)->value('id')
        ?: (int) District::query()->where('state_id', $kerala)->value('id');
}

/** A phone-verified member whose DRAFT profile holds only what registration collects. */
function draftMember(Gender $gender = Gender::Female): User
{
    $user = User::factory()->create();
    $profile = Profile::factory()->for($user)->draft()->create(['gender' => $gender]);
    $profile->forceFill([
        'last_name' => null, 'dob' => null, 'height_cm' => null, 'marital_status' => null,
        'religion_id' => null, 'caste_id' => null, 'mother_tongue_id' => null,
    ])->save();

    return $user->refresh();
}

function basicData(array $overrides = []): BasicDetailsData
{
    $hindu = masterId(Religion::class, 'HINDU');

    return new BasicDetailsData(...array_merge([
        'first_name' => 'Anjali',
        'last_name' => 'Nair',
        'gender' => Gender::Female->value,
        'dob' => now(config('oppam.display_timezone'))->subYears(26)->format('Y-m-d'),
        'height_cm' => 163,
        'weight_kg' => 55,
        'marital_status' => MaritalStatus::NeverMarried->value,
        'children_count' => 0,
        'physical_status' => PhysicalStatus::Normal->value,
        'religion_id' => $hindu,
        'caste_id' => (int) Caste::query()->where('religion_id', $hindu)->value('id'),
        'caste_no_bar' => false,
        'sub_caste' => null,
        'mother_tongue_id' => masterId(MotherTongue::class, 'MALAYALAM'),
        'star_id' => null,
        'rasi_id' => null,
        'chovva_dosham' => null,
        'papa_dosham' => null,
    ], $overrides));
}

function careerData(array $overrides = []): CareerDetailsData
{
    $india = masterId(Country::class, 'IN');
    $kerala = masterId(State::class, 'KL', ['country_id' => $india]);
    $district = keralaDistrictId();

    return new CareerDetailsData(...array_merge([
        'education_id' => (int) Education::query()->value('id'),
        'education_detail' => 'B.Tech, CUSAT',
        'employer_type' => EmployerType::Private->value,
        'occupation_id' => (int) Occupation::query()->value('id'),
        'employer_name' => 'Infopark',
        'income_band_id' => (int) IncomeBand::query()->value('id'),
        'current_country_id' => $india,
        'current_state_id' => $kerala,
        'current_district_id' => $district,
        'current_city' => 'Kochi',
        'citizenship' => null,
        'visa_status' => null,
        'permanent_country_id' => $india,
        'permanent_state_id' => $kerala,
        'permanent_district_id' => $district,
        'permanent_city' => null,
    ], $overrides));
}

function familyData(array $overrides = []): FamilyDetailsData
{
    return new FamilyDetailsData(...array_merge([
        'father_name' => 'Gopalan Nair',
        'father_occupation' => 'Retired teacher',
        'mother_name' => 'Sreedevi',
        'mother_occupation' => null,
        'brothers_married' => 1,
        'brothers_unmarried' => 0,
        'sisters_married' => 0,
        'sisters_unmarried' => 1,
        'family_status_option_id' => optionId('family_status', 'MIDDLE_CLASS'),
        'family_type_option_id' => optionId('family_type', 'NUCLEAR'),
        'family_values_option_id' => null,
        'native_place' => 'Thrissur',
        'about_family' => null,
    ], $overrides));
}

/** A DRAFT member who has finished steps 1–3 through the real Actions. */
function memberThroughStep(int $lastStep, Gender $gender = Gender::Female): User
{
    $user = draftMember($gender);
    $profile = $user->profile;

    if ($lastStep >= 1) {
        app(App\Actions\Profile\SaveBasicDetails::class)->handle($user, $profile, basicData(['gender' => $gender->value]));
    }
    if ($lastStep >= 2) {
        app(App\Actions\Profile\SaveCareerDetails::class)->handle($user, $profile->refresh(), careerData());
    }
    if ($lastStep >= 3) {
        app(App\Actions\Profile\SaveFamilyDetails::class)->handle($user, $profile->refresh(), familyData());
    }

    return $user->refresh();
}

function statusOf(User $user): ProfileStatus
{
    return $user->profile()->firstOrFail()->status;
}
