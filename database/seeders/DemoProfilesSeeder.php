<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ProfileStatus;
use App\Models\ContactDetail;
use App\Models\EducationCareer;
use App\Models\FamilyDetail;
use App\Models\HoroscopeDetail;
use App\Models\LifestyleDetail;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Rasi;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use App\Models\PartnerPreference;
use App\Models\PrivacySetting;
use App\Models\Profile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 200 fake member profiles for local development (CLAUDE.md "200 demo profiles"). Every name,
 * number and detail is invented; phones use the +91 90000 block. The first four are the
 * template's demo members (profiles-data.php). LOCAL ONLY — DatabaseSeeder never runs this in
 * testing or production. Photos arrive with the media library (P1.4).
 */
final class DemoProfilesSeeder extends Seeder
{
    private const COUNT = 200;

    private const FEMALE_NAMES = ['Anna', 'Meera', 'Divya', 'Sneha', 'Anjali', 'Reshma', 'Athira', 'Neethu', 'Aparna', 'Lakshmi', 'Devika', 'Sreya', 'Arya', 'Gayathri', 'Nimisha', 'Aswathy', 'Riya', 'Mariya', 'Ann', 'Fathima', 'Ayesha', 'Nisha', 'Parvathy', 'Keerthana', 'Anusree', 'Sandra', 'Jisha', 'Sruthy'];

    private const MALE_NAMES = ['Arun', 'Vishnu', 'Rahul', 'Nikhil', 'Jithin', 'Allen', 'Akhil', 'Anand', 'Sreejith', 'Abhijith', 'Joel', 'Kiran', 'Ajay', 'Sanjay', 'Midhun', 'Aneesh', 'Rohit', 'Hari', 'Faisal', 'Shibin', 'Tom', 'Jerin', 'Nithin', 'Vivek', 'Anoop', 'Deepak'];

    private const LAST_NAMES = ['Thomas', 'Nair', 'Krishna', 'Menon', 'Pillai', 'Varghese', 'Joseph', 'Kurian', 'Mathew', 'Das', 'Kumar', 'Raj', 'Mohan', 'George', 'Abraham', 'Namboothiri', 'Panicker', 'Kuruvilla', 'Rahman', 'Ali', 'Babu', 'Suresh', 'Varma', 'Unni'];

    /** The template's four demo members come first (profiles-data.php). */
    private const TEMPLATE_MEMBERS = [['Anna', 'Thomas', 26], ['Meera', 'Nair', 25], ['Divya', 'Krishna', 28], ['Sneha', 'Menon', 27]];

    public function run(): void
    {
        // Demo data is seeded once; a second db:seed must not double it (or collide on phones).
        if (Profile::query()->withTrashed()->exists()) {
            return;
        }

        $lookups = $this->lookups();

        DB::transaction(function () use ($lookups): void {
            for ($i = 0; $i < self::COUNT; $i++) {
                $this->createProfile($i, $lookups);
            }
        });
    }

    /** @param array<string, Collection<int, int>|Collection<int, Caste>> $lookups */
    private function createProfile(int $index, array $lookups): void
    {
        $gender = $index < count(self::TEMPLATE_MEMBERS) || $index % 2 === 0 ? Gender::Female : Gender::Male;
        [$firstName, $lastName, $age] = self::TEMPLATE_MEMBERS[$index]
            ?? [fake()->randomElement($gender === Gender::Female ? self::FEMALE_NAMES : self::MALE_NAMES), fake()->randomElement(self::LAST_NAMES), fake()->numberBetween($gender === Gender::Female ? 21 : 24, 38)];

        /** @var Caste $caste */
        $caste = $lookups['castes']->random();

        $profile = Profile::factory()
            ->state(fn (): array => [
                'gender' => $gender,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'dob' => now(config('oppam.display_timezone'))->subYears($age)->subDays(fake()->numberBetween(0, 360))->format('Y-m-d'),
                'marital_status' => fake()->boolean(90) ? MaritalStatus::NeverMarried : MaritalStatus::Divorced,
                'religion_id' => $caste->religion_id,
                'caste_id' => $caste->id,
                'mother_tongue_id' => $lookups['malayalam']->first(),
                'star_id' => $lookups['stars']->random(),
                'rasi_id' => $lookups['rasis']->random(),
                'district_id' => $lookups['districts']->random(),
                'status' => $index % 20 === 19 ? ProfileStatus::PendingReview : ProfileStatus::Active,
                'is_verified' => fake()->boolean(40),
            ])
            ->create();

        $this->createDetails($profile, $lookups);
    }

    /** @param array<string, Collection<int, int>|Collection<int, Caste>> $lookups */
    private function createDetails(Profile $profile, array $lookups): void
    {
        $india = $lookups['india']->first();

        EducationCareer::factory()->for($profile)->create([
            'education_id' => $lookups['education']->random(),
            'occupation_id' => $lookups['occupations']->random(),
            'income_band_id' => $lookups['income']->random(),
            'current_country_id' => $india,
            'current_district_id' => $profile->district_id,
        ]);
        FamilyDetail::factory()->for($profile)->create([
            'family_type_option_id' => $lookups['family_type']->random(),
            'family_status_option_id' => $lookups['family_status']->random(),
            'family_values_option_id' => $lookups['family_values']->random(),
        ]);
        PartnerPreference::factory()->for($profile)->create([
            'age_min' => max(21, $profile->age() - ($profile->gender === Gender::Male ? 7 : 1)),
            'age_max' => $profile->age() + ($profile->gender === Gender::Male ? 1 : 7),
            'religion_ids' => [$profile->religion_id],
        ]);
        ContactDetail::factory()->for($profile)->create(['country_id' => $india, 'district_id' => $profile->district_id]);
        HoroscopeDetail::factory()->for($profile)->create();
        LifestyleDetail::factory()->for($profile)->create(['diet_option_id' => $lookups['diet']->random()]);
        PrivacySetting::factory()->for($profile)->create();
    }

    /** @return array<string, Collection<int, int>|Collection<int, Caste>> */
    private function lookups(): array
    {
        $kerala = Religion::query()->whereIn('code', ['HINDU', 'CHRISTIAN', 'MUSLIM'])->pluck('id');
        $option = fn (string $group): Collection => MasterOption::query()->inGroup($group)->pluck('id');

        return [
            'castes' => Caste::query()->whereIn('religion_id', $kerala)->get(['id', 'religion_id']),
            'malayalam' => MotherTongue::query()->where('code', 'MALAYALAM')->pluck('id'),
            'stars' => Star::query()->pluck('id'),
            'rasis' => Rasi::query()->pluck('id'),
            'districts' => District::query()->pluck('id'),
            'education' => Education::query()->pluck('id'),
            'occupations' => Occupation::query()->whereNotIn('code', ['STUDENT', 'NOT_WORKING'])->pluck('id'),
            'income' => IncomeBand::query()->where('code', '!=', 'NONE')->pluck('id'),
            'india' => Country::query()->where('code', 'IN')->pluck('id'),
            'diet' => $option('diet'),
            'family_type' => $option('family_type'),
            'family_status' => $option('family_status'),
            'family_values' => $option('family_values'),
        ];
    }
}
