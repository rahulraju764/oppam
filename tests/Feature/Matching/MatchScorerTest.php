<?php

declare(strict_types=1);

use App\Domain\Matching\MatchScorer;
use App\Enums\MaritalStatus;
use App\Models\Masters\Caste;
use App\Models\Masters\Religion;
use App\Models\PartnerPreference;
use App\Models\Profile;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    seedMasters();
});

test('it scores high when preferences match both ways', function (): void {
    $scorer = new MatchScorer;

    $hindu = masterId(Religion::class, 'HINDU');
    $nair = masterId(Caste::class, 'NAIR', ['religion_id' => $hindu]);
    $ernakulam = keralaDistrictId('ERNAKULAM');

    $source = Profile::factory()->male()->create([
        'dob' => CarbonImmutable::now()->subYears(28),
        'height_cm' => 175,
        'religion_id' => $hindu,
        'caste_id' => $nair,
        'marital_status' => MaritalStatus::NeverMarried,
        'district_id' => $ernakulam,
        'completeness' => 95,
        'last_active_at' => now(),
    ]);

    PartnerPreference::factory()->create([
        'profile_id' => $source->id,
        'age_min' => 22,
        'age_max' => 26,
        'height_min_cm' => 155,
        'height_max_cm' => 168,
        'religion_ids' => [$hindu],
        'caste_ids' => [$nair],
        'district_ids' => [$ernakulam],
        'marital_statuses' => [MaritalStatus::NeverMarried->value],
    ]);

    $target = Profile::factory()->female()->create([
        'dob' => CarbonImmutable::now()->subYears(24),
        'height_cm' => 162,
        'religion_id' => $hindu,
        'caste_id' => $nair,
        'marital_status' => MaritalStatus::NeverMarried,
        'district_id' => $ernakulam,
        'completeness' => 90,
        'last_active_at' => now()->subHours(2),
    ]);

    PartnerPreference::factory()->create([
        'profile_id' => $target->id,
        'age_min' => 26,
        'age_max' => 30,
        'height_min_cm' => 170,
        'height_max_cm' => 180,
        'religion_ids' => [$hindu],
        'caste_ids' => [$nair],
        'district_ids' => [$ernakulam],
        'marital_statuses' => [MaritalStatus::NeverMarried->value],
    ]);

    $result = $scorer->calculate($source, $target);

    expect($result->totalScore)->toBeGreaterThanOrEqual(85)
        ->and($result->preferenceFit)->toBeGreaterThanOrEqual(55)
        ->and($result->reverseFit)->toBeGreaterThanOrEqual(20)
        ->and($result->activityScore)->toBe(10)
        ->and($result->completenessScore)->toBe(5);
});

test('it handles missing preferences with neutral baseline', function (): void {
    $scorer = new MatchScorer;

    $source = Profile::factory()->male()->create(['completeness' => 50]);
    $target = Profile::factory()->female()->create(['completeness' => 50, 'last_active_at' => null]);

    $result = $scorer->calculate($source, $target);

    expect($result->totalScore)->toBeGreaterThan(0)
        ->and($result->preferenceFit)->toBe(35)
        ->and($result->reverseFit)->toBe(15)
        ->and($result->activityScore)->toBe(2)
        ->and($result->completenessScore)->toBe(3);
});

test('score and cache persists row in match scores', function (): void {
    $scorer = new MatchScorer;

    $source = Profile::factory()->male()->create();
    $target = Profile::factory()->female()->create();

    $cached = $scorer->scoreAndCache($source, $target);

    expect($cached->id)->not->toBeEmpty();

    $this->assertDatabaseHas('match_scores', [
        'id' => $cached->id,
        'source_profile_id' => $source->id,
        'target_profile_id' => $target->id,
        'score' => $cached->score,
    ]);
});
