<?php

declare(strict_types=1);

use App\Domain\Matching\PreferenceMatcher;
use App\Enums\MaritalStatus;
use App\Models\Masters\Religion;
use App\Models\PartnerPreference;

/*
| P1.5 — "You match X of Y preferences" (M03) and the contact filter's meetsAll().
*/

beforeEach(fn () => seedMasters());

function preference(array $attributes): PartnerPreference
{
    return (new PartnerPreference)->forceFill($attributes);
}

it('lists only the preferences that were set, each with a tick when the candidate fits', function (): void {
    $candidate = memberThroughStep(3)->profile()->firstOrFail();   // 26, 163 cm, Hindu, never married, Malayalam
    $hindu = masterId(Religion::class, 'HINDU');

    $checks = app(PreferenceMatcher::class)->check($candidate, preference([
        'age_min' => 24, 'age_max' => 30,
        'height_min_cm' => 170,
        'marital_statuses' => [MaritalStatus::NeverMarried->value],
        'religion_ids' => [$hindu],
        'caste_ids' => [],                // empty = any: no line
    ]));

    $byLabel = collect($checks)->keyBy('label');

    expect($checks)->toHaveCount(4)
        ->and($byLabel['Age']->matches)->toBeTrue()
        ->and($byLabel['Height']->matches)->toBeFalse()
        ->and($byLabel['Height']->wanted)->toContain('or taller')
        ->and($byLabel['Marital status']->matches)->toBeTrue()
        ->and($byLabel['Religion']->wanted)->toBe('Hindu')
        ->and($byLabel['Religion']->matches)->toBeTrue();
});

it('counts an unknown candidate value as not matching and no preferences as an empty list', function (): void {
    $draft = draftMember()->profile()->firstOrFail();   // no DOB / religion yet

    expect(app(PreferenceMatcher::class)->check($draft, preference(['age_min' => 20, 'age_max' => 40]))[0]->matches)->toBeFalse()
        ->and(app(PreferenceMatcher::class)->check($draft, null))->toBe([]);
});

it('compares income by band order: a higher band meets a minimum, a lower one does not', function (): void {
    $user = memberThroughStep(2);
    $bands = App\Models\Masters\IncomeBand::query()->orderBy('sort_order')->pluck('id')->all();
    $user->profile->educationCareer->forceFill(['income_band_id' => $bands[5]])->save();
    $candidate = $user->profile()->firstOrFail();

    expect(app(PreferenceMatcher::class)->check($candidate, preference(['min_income_band_id' => $bands[3]]))[0]->matches)->toBeTrue()
        ->and(app(PreferenceMatcher::class)->check($candidate, preference(['min_income_band_id' => $bands[7]]))[0]->matches)->toBeFalse();
});

it('meetsAll is true only when every set preference matches', function (): void {
    $candidate = memberThroughStep(3)->profile()->firstOrFail();
    $matcher = app(PreferenceMatcher::class);

    expect($matcher->meetsAll($candidate, preference(['age_min' => 20, 'age_max' => 35])))->toBeTrue()
        ->and($matcher->meetsAll($candidate, preference(['age_min' => 20, 'age_max' => 35, 'height_min_cm' => 190])))->toBeFalse()
        ->and($matcher->meetsAll($candidate, null))->toBeTrue();
});
