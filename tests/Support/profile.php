<?php

declare(strict_types=1);

use App\Data\Profile\AboutDetailsData;
use App\Data\Profile\BasicDetailsData;
use App\Data\Profile\CareerDetailsData;
use App\Data\Profile\ContactDetailsData;
use App\Data\Profile\FamilyDetailsData;
use App\Data\Profile\PartnerPreferenceData;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhotoVisibility;
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

function preferenceData(array $overrides = []): PartnerPreferenceData
{
    return new PartnerPreferenceData(...array_merge([
        'age_min' => 25,
        'age_max' => 32,
        'height_min_cm' => 160,
        'height_max_cm' => 185,
        'marital_statuses' => [MaritalStatus::NeverMarried->value],
        'physical_statuses' => [],
        'religion_ids' => [masterId(Religion::class, 'HINDU')],
        'caste_ids' => [],
        'mother_tongue_ids' => [masterId(MotherTongue::class, 'MALAYALAM')],
        'star_ids' => [],
        'education_ids' => [],
        'occupation_ids' => [],
        'min_income_band_id' => null,
        'country_ids' => [],
        'district_ids' => [keralaDistrictId()],
        'diet_option_ids' => [],
        'about_partner' => null,
    ], $overrides));
}

function contactData(array $overrides = []): ContactDetailsData
{
    $india = masterId(Country::class, 'IN');

    return new ContactDetailsData(...array_merge([
        'contact_email' => 'family@example.com',
        'alternate_phone' => null,
        'contact_person' => 'Gopalan Nair',
        'contact_relation' => 'Father',
        'convenient_time' => '6 pm – 9 pm',
        'country_id' => $india,
        'state_id' => masterId(State::class, 'KL', ['country_id' => $india]),
        'district_id' => keralaDistrictId(),
        'city' => 'Kochi',
        'address_line' => null,
    ], $overrides));
}

function aboutData(array $overrides = []): AboutDetailsData
{
    return new AboutDetailsData(...array_merge([
        'about' => 'I am a software engineer in Kochi who loves music, travel and time with family.',
        'diet_option_id' => optionId('diet', 'VEG'),
        'smoking_option_id' => null,
        'drinking_option_id' => null,
        'hobbies' => ['Music', 'Travel'],
        'photo_visibility' => PhotoVisibility::AllMembers->value,
    ], $overrides));
}

/**
 * Upload a real (generated) photo through UploadProfilePhoto. Media disks are faked, so files land
 * in a throwaway folder. Conversions (GD resize, blur, watermark) are skipped unless $convert —
 * tests about photo URLs ask for them; everything else stays fast.
 */
function addTestPhoto(User $user, int $width = 600, int $height = 800, bool $convert = false): App\Models\Media
{
    fakeMediaDisks();

    if (! $convert) {
        Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
    }

    return app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle(
        $user,
        $user->profile()->firstOrFail(),
        Illuminate\Http\UploadedFile::fake()->image('photo.jpg', $width, $height),
    );
}

/** Fake the private + public media disks once per test (a second fake would wipe the first's files). */
function fakeMediaDisks(): void
{
    if (! app()->bound('oppam.media-faked')) {
        Illuminate\Support\Facades\Storage::fake((string) config('oppam.media.private_disk'));
        Illuminate\Support\Facades\Storage::fake((string) config('oppam.media.public_disk'));
        app()->instance('oppam.media-faked', true);
    }
}

/** A DRAFT member who has finished steps 1–$lastStep through the real Actions (step 6 includes one photo). */
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
    if ($lastStep >= 4) {
        app(App\Actions\Profile\SavePartnerPreferences::class)->handle($user, $profile->refresh(), preferenceData());
    }
    if ($lastStep >= 5) {
        app(App\Actions\Profile\SaveContactDetails::class)->handle($user, $profile->refresh(), contactData());
    }
    if ($lastStep >= 6) {
        app(App\Actions\Profile\SaveAboutDetails::class)->handle($user, $profile->refresh(), aboutData());
        addTestPhoto($user);
    }

    return $user->refresh();
}

function statusOf(User $user): ProfileStatus
{
    return $user->profile()->firstOrFail()->status;
}

/** A real JPEG with an APP1 EXIF segment carrying a GPS tag (for "EXIF is stripped" tests). */
function jpegWithGps(string $name = 'gps.jpg'): Illuminate\Http\UploadedFile
{
    $file = Illuminate\Http\UploadedFile::fake()->image($name, 600, 800);
    $bytes = (string) file_get_contents($file->getRealPath());
    $app1 = "Exif\x00\x00MM\x00*\x00\x00\x00\x08\x00\x00GPSLatitude-10.0889";
    file_put_contents($file->getRealPath(), substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($bytes, 2));

    return $file;
}

/** A colourful 800×1000 JPEG (gradient + shapes), so blur / pixelation can be measured. */
function gradientPhoto(string $name = 'gradient.jpg'): Illuminate\Http\UploadedFile
{
    $image = imagecreatetruecolor(800, 1000);
    for ($y = 0; $y < 1000; $y++) {
        for ($x = 0; $x < 800; $x += 4) {
            imagefilledrectangle($image, $x, $y, $x + 3, $y, (int) imagecolorallocate($image, intdiv($x * 255, 800), intdiv($y * 255, 1000), intdiv(($x + $y) * 255, 1800)));
        }
    }
    imagefilledellipse($image, 400, 400, 300, 360, (int) imagecolorallocate($image, 250, 220, 200));

    $path = tempnam(sys_get_temp_dir(), 'opm-test-').'.jpg';
    imagejpeg($image, $path, 92);
    imagedestroy($image);

    return new Illuminate\Http\UploadedFile($path, $name, 'image/jpeg', null, true);
}

/** An ACTIVE, onboarded male member — a typical viewer of the (female) test profiles. */
function groom(string $e164 = '+919800000099'): User
{
    $user = memberWithPhone($e164);
    $user->profile->forceFill(['gender' => Gender::Male])->save();

    return $user->refresh();
}

/** An ACTIVE, onboarded female member. */
function bride(string $e164 = '+919800000098'): User
{
    $user = memberWithPhone($e164);
    $user->profile->forceFill(['gender' => Gender::Female])->save();

    return $user->refresh();
}
