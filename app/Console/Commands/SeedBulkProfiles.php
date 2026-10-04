<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesBenchDatabase;
use App\Enums\CreatedFor;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Rasi;
use App\Models\Masters\Star;
use App\Models\Masters\State;
use Database\Seeders\MastersSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * P2.1 (M04 "p95 < 800 ms at 100k profiles"): fill the separate bench database with N fake members
 * — users, profiles (mostly ACTIVE, both genders, realistic spreads of age, religion / caste,
 * district, flags) and their career / lifestyle rows — with chunked raw inserts. Fake data only
 * (CLAUDE.md rule 11). Run profiles:search-benchmark afterwards.
 */
final class SeedBulkProfiles extends Command
{
    use UsesBenchDatabase;

    private const CHUNK = 1000;

    private const FIRST = ['Anjali', 'Meera', 'Lakshmi', 'Anu', 'Divya', 'Sneha', 'Arya', 'Gopika', 'Arun', 'Rahul', 'Vishnu', 'Akhil', 'Nikhil', 'Joseph', 'Anoop', 'Faisal'];

    private const LAST = ['Nair', 'Menon', 'Pillai', 'Varghese', 'Thomas', 'Kurian', 'Panicker', 'Warrier', 'Kumar', 'Das', 'Mathew', 'Rahman'];

    protected $signature = 'profiles:seed-bulk {count=100000 : how many members to add} {--fresh : drop and re-create the bench database first}';

    protected $description = 'Fill the bench database with fake members for search performance tests (local only)';

    public function handle(): int
    {
        if (! $this->benchAllowed()) {
            return self::FAILURE;
        }

        $database = $this->benchDatabase();
        $server = DB::connection();
        if ($this->option('fresh')) {
            $server->statement("DROP DATABASE IF EXISTS `{$database}`");
        }
        $server->statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->useBench();
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => MastersSeeder::class, '--force' => true]);

        $count = max(1, (int) $this->argument('count'));
        $masters = $this->masters();
        $password = Hash::make('password');
        $offset = (int) DB::table('users')->count();
        $bar = $this->output->createProgressBar($count);

        for ($done = 0; $done < $count; $done += self::CHUNK) {
            $rows = $this->chunk($offset + $done, min(self::CHUNK, $count - $done), $masters, $password);
            DB::transaction(function () use ($rows): void {
                foreach (['users', 'profiles', 'education_careers', 'lifestyle_details', 'partner_preferences', 'privacy_settings'] as $table) {
                    if ($rows[$table] !== []) {
                        DB::table($table)->insert($rows[$table]);
                    }
                }
            });
            $bar->advance(count($rows['profiles']));
        }

        $bar->finish();
        $this->newLine();
        $this->info("Bench database `{$database}` now has ".DB::table('profiles')->count().' profiles.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> master ids the generator draws from */
    private function masters(): array
    {
        $kerala = (int) State::query()->where('code', 'KL')->value('id');

        return [
            'castes' => Caste::query()->get(['id', 'religion_id'])->map(fn ($c): array => [(int) $c->id, (int) $c->religion_id])->all(),
            'tongues' => MotherTongue::query()->pluck('id')->all(),
            'stars' => Star::query()->pluck('id')->all(),
            'rasis' => Rasi::query()->pluck('id')->all(),
            'districts' => District::query()->where('state_id', $kerala)->pluck('id')->all(),
            'education' => Education::query()->pluck('id')->all(),
            'occupations' => Occupation::query()->pluck('id')->all(),
            'income' => IncomeBand::query()->pluck('id')->all(),
            'diet' => MasterOption::query()->where('group', 'diet')->pluck('id')->all(),
            'india' => (int) Country::query()->where('code', 'IN')->value('id'),
            'abroad' => Country::query()->where('code', '!=', 'IN')->pluck('id')->all(),
            'kerala' => $kerala,
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, list<array<string, mixed>>>
     */
    private function chunk(int $from, int $size, array $m, string $password): array
    {
        $rows = ['users' => [], 'profiles' => [], 'education_careers' => [], 'lifestyle_details' => [], 'partner_preferences' => [], 'privacy_settings' => []];
        $now = now();
        $pick = fn (array $list): mixed => $list[array_rand($list)];

        for ($i = 0; $i < $size; $i++) {
            $n = $from + $i + 1;
            $userId = (string) Str::ulid();
            $profileId = (string) Str::ulid();
            $female = $n % 2 === 0;
            [$casteId, $religionId] = $pick($m['castes']);
            $active = mt_rand(1, 100) <= 97;
            $published = $now->copy()->subMinutes(mt_rand(0, 2 * 365 * 24 * 60));
            $abroad = mt_rand(1, 100) <= 10;

            $rows['users'][] = [
                'id' => $userId, 'phone' => '+917'.str_pad((string) $n, 9, '0', STR_PAD_LEFT), 'email' => null,
                'password' => $password, 'role' => UserRole::Member->value,
                'created_for' => mt_rand(1, 100) <= 60 ? CreatedFor::Self->value : $pick([CreatedFor::Son->value, CreatedFor::Daughter->value, CreatedFor::Brother->value]),
                'phone_verified_at' => $now, 'status' => UserStatus::Active->value, 'session_epoch' => 0,
                'created_at' => $published, 'updated_at' => $published,
            ];

            $rows['profiles'][] = [
                'id' => $profileId, 'user_id' => $userId, 'code' => 'OPM'.(7_000_000 + $n),
                'gender' => $female ? Gender::Female->value : Gender::Male->value,
                'first_name' => $pick(self::FIRST), 'last_name' => $pick(self::LAST),
                'dob' => $now->copy()->subYears(mt_rand(21, 45))->subDays(mt_rand(0, 364))->toDateString(),
                'height_cm' => $female ? mt_rand(148, 175) : mt_rand(160, 190), 'weight_kg' => mt_rand(45, 95),
                'marital_status' => mt_rand(1, 100) <= 88 ? MaritalStatus::NeverMarried->value : $pick([MaritalStatus::Divorced->value, MaritalStatus::Widowed->value]),
                'children_count' => 0, 'physical_status' => PhysicalStatus::Normal->value,
                'religion_id' => $religionId, 'caste_id' => $casteId, 'caste_no_bar' => mt_rand(1, 100) <= 5,
                'mother_tongue_id' => $pick($m['tongues']), 'star_id' => $pick($m['stars']), 'rasi_id' => $pick($m['rasis']),
                'district_id' => $pick($m['districts']),
                'status' => $active ? ProfileStatus::Active->value : ProfileStatus::PendingReview->value,
                'is_verified' => mt_rand(1, 100) <= 20, 'is_premium' => mt_rand(1, 100) <= 15,
                'highlighted_until' => mt_rand(1, 100) <= 2 ? $now->copy()->addDays(10) : null,
                'completeness' => mt_rand(55, 100), 'published_at' => $active ? $published : null,
                'last_active_at' => $now->copy()->subMinutes(mt_rand(0, 120 * 24 * 60)),
                'created_at' => $published, 'updated_at' => $published,
            ];

            $rows['education_careers'][] = [
                'profile_id' => $profileId, 'education_id' => $pick($m['education']), 'occupation_id' => $pick($m['occupations']),
                'income_band_id' => $pick($m['income']), 'employer_type' => $pick(array_map(fn (EmployerType $e): string => $e->value, EmployerType::cases())),
                'current_country_id' => $abroad && $m['abroad'] !== [] ? $pick($m['abroad']) : $m['india'],
                'current_state_id' => $abroad ? null : $m['kerala'], 'current_district_id' => $abroad ? null : $pick($m['districts']),
                'created_at' => $published, 'updated_at' => $published,
            ];

            if (mt_rand(1, 100) <= 70) {
                $rows['lifestyle_details'][] = ['profile_id' => $profileId, 'diet_option_id' => $pick($m['diet']), 'created_at' => $published, 'updated_at' => $published];
            }

            if (mt_rand(1, 100) <= 60) {   // gives the Relevance score something to rank on
                $rows['partner_preferences'][] = [
                    'profile_id' => $profileId, 'age_min' => mt_rand(21, 28), 'age_max' => mt_rand(29, 40),
                    'religion_ids' => json_encode([$religionId]), 'marital_statuses' => json_encode([MaritalStatus::NeverMarried->value]),
                    'district_ids' => json_encode([$pick($m['districts'])]), 'created_at' => $published, 'updated_at' => $published,
                ];
            }

            // Like real members (onboarding creates one for everybody): a privacy row each, ~2% incognito.
            $rows['privacy_settings'][] = ['profile_id' => $profileId, 'incognito' => mt_rand(1, 100) <= 2, 'created_at' => $published, 'updated_at' => $published];
        }

        return $rows;
    }
}
