<?php

declare(strict_types=1);

use App\Domain\Matching\MatchFunnel;
use App\Enums\MaritalStatus;
use App\Enums\MatchTab;
use App\Models\Block;
use App\Models\Masters\Religion;
use App\Models\PartnerPreference;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Profile\ProfileSearch;
use Illuminate\Support\Facades\Cache;

/*
| P2.3 — the match funnel (M05; owner decision 2026-10-04): All = profiles that meet MY partner
| preferences, Mutual = those that also accept ME, Viewed / Yet to be viewed = by me, Near me =
| my district. Every tab is a ProfileSearch (blocked pairs never appear). Counts are cached.
*/

beforeEach(function (): void {
    seedMasters();
});

/** A 30-year-old Hindu, never-married groom who wants a Hindu bride aged 24–28. */
function funnelGroom(): User
{
    $groom = groom('+919822200001');
    $groom->profile->forceFill([
        'dob' => now()->subYears(30)->subMonths(2), 'religion_id' => masterId(Religion::class, 'HINDU'),
        'marital_status' => MaritalStatus::NeverMarried, 'district_id' => keralaDistrictId('ERNAKULAM'),
    ])->save();
    PartnerPreference::query()->where('profile_id', $groom->profile->id)->delete();
    $pref = PartnerPreference::factory()->make([
        'age_min' => 24, 'age_max' => 28, 'religion_ids' => [masterId(Religion::class, 'HINDU')],
        'marital_statuses' => null, 'caste_ids' => null, 'mother_tongue_ids' => null, 'district_ids' => null,
    ]);
    $pref->profile_id = $groom->profile->id;
    $pref->save();

    return $groom->refresh();
}

/** @param  array<string, mixed>|null  $wants  her partner preferences (null = none at all) */
function funnelBride(int $age = 26, string $religion = 'HINDU', ?array $wants = null, array $state = []): Profile
{
    $bride = Profile::factory()->female()->active()->create([
        'dob' => now()->subYears($age)->subMonths(3), 'religion_id' => masterId(Religion::class, $religion), ...$state,
    ]);
    PartnerPreference::query()->where('profile_id', $bride->id)->delete();
    if ($wants !== null) {
        $pref = PartnerPreference::factory()->make(['age_min' => null, 'age_max' => null, 'religion_ids' => null,
            'marital_statuses' => null, ...$wants]);
        $pref->profile_id = $bride->id;
        $pref->save();
    }

    return $bride;
}

/** @return list<string> */
function tabCodes(User $member, MatchTab $tab): array
{
    $criteria = app(MatchFunnel::class)->criteria($member->profile, $tab);

    return $criteria === null ? [] : app(ProfileSearch::class)->query($member->profile, $criteria)->orderBy('code')->pluck('code')->all();
}

it('M05 All: profiles that meet my preferences (age, religion), whatever theirs say', function (): void {
    $groom = funnelGroom();
    $fits = funnelBride(26, wants: ['age_min' => 40]);       // she wants older men — still "meets mine"
    funnelBride(32);                                         // too old for me
    funnelBride(26, 'CHRISTIAN');                            // other religion

    expect(tabCodes($groom, MatchTab::All))->toBe([$fits->code]);
});

it('M05 Mutual: they meet mine AND their preferences accept me (age, religion, marital; empty = any)', function (): void {
    $groom = funnelGroom();
    $hindu = masterId(Religion::class, 'HINDU');
    $open = funnelBride(25);                                                              // no preferences at all
    $accepts = funnelBride(26, wants: ['age_min' => 28, 'age_max' => 34, 'religion_ids' => [(string) $hindu], 'marital_statuses' => ['NEVER_MARRIED']]);
    funnelBride(26, wants: ['age_max' => 29]);                                            // I'm too old for her
    funnelBride(26, wants: ['religion_ids' => [masterId(Religion::class, 'CHRISTIAN')]]); // wants another religion
    funnelBride(26, wants: ['marital_statuses' => ['DIVORCED']]);                         // wants another status

    expect(tabCodes($groom, MatchTab::Mutual))->toBe(collect([$open->code, $accepts->code])->sort()->values()->all());
});

it('Viewed and Yet to be viewed split by my profile views; Viewed ignores my preferences', function (): void {
    $groom = funnelGroom();
    $seen = funnelBride(26);
    $unseen = funnelBride(27);
    $seenOutsidePrefs = funnelBride(35);
    foreach ([$seen, $seenOutsidePrefs] as $p) {
        ProfileView::factory()->create(['viewer_profile_id' => $groom->profile->id, 'viewed_profile_id' => $p->id]);
    }

    expect(tabCodes($groom, MatchTab::Unviewed))->toBe([$unseen->code])
        ->and(tabCodes($groom, MatchTab::Viewed))->toBe(collect([$seen->code, $seenOutsidePrefs->code])->sort()->values()->all());
});

it('Near me = my district; without a district the tab is empty', function (): void {
    $groom = funnelGroom();
    $near = funnelBride(26, state: ['district_id' => keralaDistrictId('ERNAKULAM')]);
    funnelBride(26, state: ['district_id' => keralaDistrictId('KANNUR')]);

    expect(tabCodes($groom, MatchTab::NearMe))->toBe([$near->code]);

    $groom->profile->forceFill(['district_id' => null])->save();
    expect(app(MatchFunnel::class)->criteria($groom->refresh()->profile, MatchTab::NearMe))->toBeNull();
});

it('R-M03-1: a blocked pair appears in no tab, in either direction', function (): void {
    $groom = funnelGroom();
    $blockedByMe = funnelBride(26);
    $blockedMe = funnelBride(27);
    Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $blockedByMe->id]);
    Block::factory()->create(['blocker_profile_id' => $blockedMe->id, 'blocked_profile_id' => $groom->profile->id]);
    ProfileView::factory()->create(['viewer_profile_id' => $groom->profile->id, 'viewed_profile_id' => $blockedByMe->id]);

    foreach (MatchTab::cases() as $tab) {
        expect(tabCodes($groom, $tab))->not->toContain($blockedByMe->code)->not->toContain($blockedMe->code);
    }
});

it('counts every tab once and caches them per member for COUNTS_TTL', function (): void {
    $groom = funnelGroom();
    funnelBride(26);

    $funnel = app(MatchFunnel::class);
    $counts = $funnel->counts($groom->profile);
    expect($counts)->toHaveKeys(array_map(fn (MatchTab $t): string => $t->value, MatchTab::cases()))
        ->and($counts['all'])->toBe(1);

    funnelBride(25);
    expect($funnel->counts($groom->profile)['all'])->toBe(1);   // cached

    $funnel->forgetCounts($groom->profile);
    expect($funnel->counts($groom->profile)['all'])->toBe(2)
        ->and(Cache::has('matches:counts:'.$groom->profile->id))->toBeTrue();
});
