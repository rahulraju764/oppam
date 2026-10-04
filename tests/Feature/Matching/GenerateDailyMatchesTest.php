<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Domain\Matching\MatchScorer;
use App\Domain\Safety\BlockList;
use App\Enums\MaritalStatus;
use App\Jobs\Matching\GenerateDailyMatches;
use App\Models\Block;
use App\Models\DailyMatch;
use App\Models\Masters\Caste;
use App\Models\Masters\Religion;
use App\Models\PartnerPreference;
use App\Models\Profile;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    seedMasters();
});

test('it generates daily matches for active member with diversity cap', function (): void {
    $hindu = masterId(Religion::class, 'HINDU');
    $nair = masterId(Caste::class, 'NAIR', ['religion_id' => $hindu]);
    $ernakulam = keralaDistrictId('ERNAKULAM');
    $thrissur = keralaDistrictId('THRISSUR');
    $palakkad = keralaDistrictId('PALAKKAD');
    $kannur = keralaDistrictId('KANNUR');

    $source = Profile::factory()->active()->male()->create([
        'dob' => CarbonImmutable::now()->subYears(28),
        'religion_id' => $hindu,
        'caste_id' => $nair,
        'marital_status' => MaritalStatus::NeverMarried,
        'district_id' => $ernakulam,
        'published_at' => now(),
    ]);

    PartnerPreference::factory()->create([
        'profile_id' => $source->id,
        'age_min' => 20,
        'age_max' => 27,
        'religion_ids' => [$hindu],
        'marital_statuses' => [MaritalStatus::NeverMarried->value],
    ]);

    // Create 5 candidates from Ernakulam (diversity cap says max 3 should be chosen)
    Profile::factory()->count(5)->active()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(24),
        'religion_id' => $hindu,
        'caste_id' => $nair,
        'marital_status' => MaritalStatus::NeverMarried,
        'district_id' => $ernakulam,
        'published_at' => now(),
    ]);

    // Create 3 candidates from Thrissur
    Profile::factory()->count(3)->active()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(25),
        'religion_id' => $hindu,
        'caste_id' => $nair,
        'marital_status' => MaritalStatus::NeverMarried,
        'district_id' => $thrissur,
        'published_at' => now(),
    ]);

    // Create 3 candidates from Palakkad
    Profile::factory()->count(3)->active()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(23),
        'religion_id' => $hindu,
        'caste_id' => $nair,
        'marital_status' => MaritalStatus::NeverMarried,
        'district_id' => $palakkad,
        'published_at' => now(),
    ]);

    // Create 3 candidates from Kannur
    Profile::factory()->count(3)->active()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(26),
        'religion_id' => $hindu,
        'caste_id' => $nair,
        'marital_status' => MaritalStatus::NeverMarried,
        'district_id' => $kannur,
        'published_at' => now(),
    ]);

    $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

    $job = new GenerateDailyMatches([$source->id], $today);
    $job->handle(
        app(MatchScorer::class),
        app(BlockList::class),
        app(SettingsRepository::class)
    );

    $matches = DailyMatch::query()
        ->where('profile_id', $source->id)
        ->where('match_date', $today)
        ->with('matchedProfile')
        ->get();

    // Limit is 10
    expect($matches->count())->toBe(10);

    // Assert diversity cap: no single district has more than 3 matches
    $districtGroup = $matches->groupBy(fn (DailyMatch $m) => $m->matchedProfile->district_id);
    foreach ($districtGroup as $districtId => $group) {
        expect($group->count())->toBeLessThanOrEqual(3);
    }
});

test('it excludes blocked profiles from daily matches', function (): void {
    $hindu = masterId(Religion::class, 'HINDU');
    $ernakulam = keralaDistrictId('ERNAKULAM');

    $source = Profile::factory()->active()->male()->create([
        'dob' => CarbonImmutable::now()->subYears(28),
        'religion_id' => $hindu,
        'district_id' => $ernakulam,
        'published_at' => now(),
    ]);

    $blockedFemale = Profile::factory()->active()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(25),
        'religion_id' => $hindu,
        'district_id' => $ernakulam,
        'published_at' => now(),
    ]);

    // Block relationship
    Block::factory()->create([
        'blocker_profile_id' => $source->id,
        'blocked_profile_id' => $blockedFemale->id,
    ]);

    $normalFemale = Profile::factory()->active()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(25),
        'religion_id' => $hindu,
        'district_id' => $ernakulam,
        'published_at' => now(),
    ]);

    $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

    $job = new GenerateDailyMatches([$source->id], $today);
    $job->handle(
        app(MatchScorer::class),
        app(BlockList::class),
        app(SettingsRepository::class)
    );

    $matchedIds = DailyMatch::query()
        ->where('profile_id', $source->id)
        ->where('match_date', $today)
        ->pluck('matched_profile_id')
        ->all();

    expect($matchedIds)->toContain($normalFemale->id)
        ->and($matchedIds)->not->toContain($blockedFemale->id);
});

test('it updates without duplicating when re-run on same day', function (): void {
    $hindu = masterId(Religion::class, 'HINDU');
    $ernakulam = keralaDistrictId('ERNAKULAM');

    $source = Profile::factory()->active()->male()->create([
        'dob' => CarbonImmutable::now()->subYears(28),
        'religion_id' => $hindu,
        'district_id' => $ernakulam,
        'published_at' => now(),
    ]);

    Profile::factory()->count(3)->active()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(25),
        'religion_id' => $hindu,
        'district_id' => $ernakulam,
        'published_at' => now(),
    ]);

    $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

    $job = new GenerateDailyMatches([$source->id], $today);
    $job->handle(
        app(MatchScorer::class),
        app(BlockList::class),
        app(SettingsRepository::class)
    );

    $firstCount = DailyMatch::query()
        ->where('profile_id', $source->id)
        ->where('match_date', $today)
        ->count();

    expect($firstCount)->toBe(3);

    // Run again
    $job->handle(
        app(MatchScorer::class),
        app(BlockList::class),
        app(SettingsRepository::class)
    );

    $secondCount = DailyMatch::query()
        ->where('profile_id', $source->id)
        ->where('match_date', $today)
        ->count();

    expect($secondCount)->toBe(3);
});
