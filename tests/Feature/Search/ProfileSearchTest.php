<?php

declare(strict_types=1);

use App\Actions\Search\SearchProfiles;
use App\Data\Search\SearchCriteria;
use App\Enums\MaritalStatus;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Search\SearchThrottled;
use App\Models\Block;
use App\Models\EducationCareer;
use App\Models\Ignore;
use App\Models\LifestyleDetail;
use App\Models\Masters\MasterOption;
use App\Models\Masters\Religion;
use App\Models\PartnerPreference;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\Setting;
use App\Models\User;
use App\Services\Profile\ProfileSearch;

/*
| P2.1 — M04 search service: the always-on exclusions (status, gender, own profile, blocked either
| way, ignored, incognito, inactive account), the filters, the sorts and keyset paging.
*/

beforeEach(function (): void {
    seedMasters();
});

/** A searcher (groom) and the codes search returns for them. */
function searchCodes(User $searcher, array $filters = []): array
{
    return app(ProfileSearch::class)->query($searcher->profile, SearchCriteria::fromInput($filters))->pluck('code')->all();
}

function bride2(array $attributes = []): Profile
{
    return Profile::factory()->female()->active()->create($attributes);
}

// ---- Always-on exclusions ----------------------------------------------------------------------

it('M04: only ACTIVE profiles of the opposite gender, never the searcher', function (): void {
    $me = groom('+919877700001');
    $visible = bride2();
    $pending = Profile::factory()->female()->pendingReview()->create();
    $hidden = Profile::factory()->female()->create(['status' => ProfileStatus::Hidden]);
    $man = Profile::factory()->male()->active()->create();

    $codes = searchCodes($me);

    expect($codes)->toContain($visible->code)
        ->not->toContain($pending->code)->not->toContain($hidden->code)
        ->not->toContain($man->code)->not->toContain($me->profile->code);
});

it('M04 acceptance: blocked (either direction) and ignored profiles never appear', function (): void {
    $me = groom('+919877700002');
    [$iBlocked, $blockedMe, $iIgnored, $ignoredMe] = [bride2(), bride2(), bride2(), bride2()];
    Block::factory()->create(['blocker_profile_id' => $me->profile->id, 'blocked_profile_id' => $iBlocked->id]);
    Block::factory()->create(['blocker_profile_id' => $blockedMe->id, 'blocked_profile_id' => $me->profile->id]);
    Ignore::factory()->create(['ignorer_profile_id' => $me->profile->id, 'ignored_profile_id' => $iIgnored->id]);
    Ignore::factory()->create(['ignorer_profile_id' => $ignoredMe->id, 'ignored_profile_id' => $me->profile->id]);

    $codes = searchCodes($me);

    expect($codes)->not->toContain($iBlocked->code)->not->toContain($blockedMe->code)->not->toContain($iIgnored->code)
        ->toContain($ignoredMe->code);   // an ignore is one-way: being ignored doesn't hide them from me
});

it('M04: incognito profiles and profiles of inactive accounts never appear', function (): void {
    $me = groom('+919877700003');
    $incognito = bride2();
    PrivacySetting::factory()->create(['profile_id' => $incognito->id, 'incognito' => true]);
    // An account suspended through A03 makes its profile SUSPENDED too (covered above); this is the
    // safety net for an inconsistent row: page() re-checks the owning account before showing it.
    $suspendedAccount = bride2();
    $suspendedAccount->user->forceFill(['status' => UserStatus::Suspended])->save();
    $shown = app(ProfileSearch::class)->page($me->profile, SearchCriteria::fromInput([]))->profiles->pluck('code')->all();

    expect(searchCodes($me))->not->toContain($incognito->code)
        ->and($shown)->not->toContain($suspendedAccount->code)->not->toContain($incognito->code);
});

// ---- Filters -----------------------------------------------------------------------------------

it('filters by age, height, marital status and religion / caste (with caste-no-bar)', function (): void {
    $me = groom('+919877700004');
    $young = bride2(['dob' => now()->subYears(23)->subDays(5)->toDateString(), 'height_cm' => 160]);
    $older = bride2(['dob' => now()->subYears(33)->subDays(5)->toDateString(), 'height_cm' => 170]);
    $divorced = bride2(['marital_status' => MaritalStatus::Divorced]);
    $noBar = bride2(['caste_no_bar' => true]);

    expect(searchCodes($me, ['age_min' => 22, 'age_max' => 25]))->toContain($young->code)->not->toContain($older->code)
        ->and(searchCodes($me, ['height_min' => 165]))->toContain($older->code)->not->toContain($young->code)
        ->and(searchCodes($me, ['marital' => ['DIVORCED']]))->toBe([$divorced->code])
        ->and(searchCodes($me, ['religion' => $young->religion_id, 'caste' => [$young->caste_id]]))->toContain($young->code)
        ->and(searchCodes($me, ['caste' => [$young->caste_id], 'caste_no_bar' => '1']))->toContain($noBar->code)->toContain($young->code);
});

it('filters on career, location, lifestyle, photo / verified / premium and newly joined', function (): void {
    $me = groom('+919877700005');
    $vegetarian = bride2();
    $diet = MasterOption::query()->where('group', 'diet')->where('code', 'VEG')->firstOrFail();
    LifestyleDetail::factory()->create(['profile_id' => $vegetarian->id, 'diet_option_id' => $diet->id]);
    $abroad = bride2();
    $uae = App\Models\Masters\Country::query()->where('code', '!=', 'IN')->firstOrFail();
    EducationCareer::factory()->create(['profile_id' => $abroad->id, 'current_country_id' => $uae->id]);
    $verified = bride2(['is_verified' => true, 'is_premium' => true]);
    $old = bride2(['published_at' => now()->subDays(30)]);

    expect(searchCodes($me, ['diet' => $diet->id]))->toBe([$vegetarian->code])
        ->and(searchCodes($me, ['nri' => '1']))->toBe([$abroad->code])
        ->and(searchCodes($me, ['verified' => '1']))->toBe([$verified->code])
        ->and(searchCodes($me, ['premium' => '1']))->toBe([$verified->code])
        ->and(searchCodes($me, ['new' => '1']))->not->toContain($old->code)
        ->and(searchCodes($me, ['photo' => '1']))->toBe([]);   // nobody has an approved photo here
});

it('can hide profiles I have already viewed', function (): void {
    $me = groom('+919877700006');
    $seen = bride2();
    $unseen = bride2();
    ProfileView::factory()->create(['viewer_profile_id' => $me->profile->id, 'viewed_profile_id' => $seen->id]);

    expect(searchCodes($me, ['hide_viewed' => '1']))->toContain($unseen->code)->not->toContain($seen->code);
});

it('ignores unknown, out-of-range and SQL-ish filter values', function (): void {
    $me = groom('+919877700007');
    $bride = bride2();

    expect(searchCodes($me, [
        'age_min' => '5', 'age_max' => "30' OR 1=1 --", 'religion' => '-1', 'caste' => ['x', '0'], 'marital' => ['NOT_A_STATUS'],
        'sort' => 'users.password', 'employer' => 'DROP TABLE', 'active' => '999d', 'nonsense' => 'yes',
    ]))->toContain($bride->code);
});

// ---- Sorts and paging --------------------------------------------------------------------------

it('pages with a stable cursor: no duplicates, no gaps, newest first', function (): void {
    $me = groom('+919877700008');
    foreach (range(1, 45) as $i) {
        bride2(['published_at' => now()->subMinutes($i % 7)]);   // many ties on published_at
    }

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = app(ProfileSearch::class)->page($me->profile, SearchCriteria::fromInput(['sort' => 'newest']), $cursor);
        $seen = [...$seen, ...$page->profiles->pluck('code')->all()];
        $cursor = $page->nextCursor;
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)
        ->and(count($seen))->toBe(45)
        ->and(count(array_unique($seen)))->toBe(45);
});

it('P2.1 review: every sort pages without duplicates or gaps — ties, missing last-active, relevance scores', function (string $sort): void {
    $me = groom('+919877700014');
    $religion = Religion::query()->where('code', 'HINDU')->firstOrFail();
    $other = Religion::query()->where('code', 'CHRISTIAN')->firstOrFail();
    PartnerPreference::factory()->create(['profile_id' => $me->profile->id, 'religion_ids' => [$religion->id]]);
    $same = now()->subHour();
    foreach (range(1, 47) as $i) {
        bride2([
            'published_at' => $i % 3 === 0 ? $same : now()->subMinutes($i),   // many exact ties
            'last_active_at' => $i % 4 === 0 ? null : now()->subMinutes($i % 5),
            'is_verified' => $i % 2 === 0,
            'religion_id' => $i % 2 === 0 ? $religion->id : $other->id,   // two relevance scores, many ties
            'caste_id' => null,
        ]);
    }

    $criteria = SearchCriteria::fromInput(['sort' => $sort]);
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = app(ProfileSearch::class)->page($me->profile, $criteria, $cursor);
        $seen = [...$seen, ...$page->profiles->pluck('code')->all()];
        $cursor = $page->nextCursor;
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect(count($seen))->toBe(app(ProfileSearch::class)->count($me->profile, $criteria))
        ->and(count(array_unique($seen)))->toBe(count($seen))
        ->and($pages)->toBe(3);
})->with(['relevance', 'newest', 'active', 'verified']);

it('a tampered cursor just starts again from the first page', function (): void {
    $me = groom('+919877700009');
    bride2();

    $page = app(ProfileSearch::class)->page($me->profile, SearchCriteria::fromInput([]), 'not-a-cursor"); DROP TABLE profiles; --');

    expect($page->profiles)->toHaveCount(1);
});

it('Relevance ranks profiles meeting more of my partner preferences first, premium as a tie-breaker', function (): void {
    $me = groom('+919877700010');
    $religion = Religion::query()->where('code', 'HINDU')->firstOrFail();
    // Only these two preferences (the factory's age / height ranges would let random data score too).
    PartnerPreference::factory()->create(['profile_id' => $me->profile->id, 'religion_ids' => [$religion->id], 'marital_statuses' => ['NEVER_MARRIED'],
        'age_min' => null, 'age_max' => null, 'height_min_cm' => null, 'height_max_cm' => null]);
    $match = bride2(['religion_id' => $religion->id, 'caste_id' => null]);
    $partial = bride2(['marital_status' => MaritalStatus::Divorced, 'religion_id' => $religion->id, 'caste_id' => null]);
    $premiumNone = bride2(['marital_status' => MaritalStatus::Divorced, 'is_premium' => true,
        'religion_id' => Religion::query()->where('code', 'CHRISTIAN')->value('id'), 'caste_id' => null]);

    $codes = app(ProfileSearch::class)->page($me->profile, SearchCriteria::fromInput([]))->profiles->pluck('code')->all();

    expect(array_search($match->code, $codes, true))->toBeLessThan(array_search($partial->code, $codes, true))
        ->and(array_search($partial->code, $codes, true))->toBeLessThan(array_search($premiumNone->code, $codes, true));
});

it('the count matches the full result set', function (): void {
    $me = groom('+919877700011');
    foreach (range(1, 25) as $i) {
        bride2();
    }

    expect(app(ProfileSearch::class)->count($me->profile, SearchCriteria::fromInput([])))->toBe(25);
});

// ---- The Action: who may search, rate limit --------------------------------------------------------

it('a member whose own profile is suspended gets no results', function (): void {
    $me = groom('+919877700012');
    bride2();
    $me->profile->forceFill(['status' => ProfileStatus::Suspended])->save();

    $result = app(SearchProfiles::class)->handle($me->refresh(), SearchCriteria::fromInput([]));

    expect($result->page->profiles)->toHaveCount(0)->and($result->total)->toBe(0);
});

it('CLAUDE.md rule 10: searches are rate-limited per member (search.max_per_minute)', function (): void {
    $me = groom('+919877700013');
    Setting::factory()->keyed(SettingKey::SearchMaxPerMinute, 10)->create();

    foreach (range(1, 10) as $i) {
        app(SearchProfiles::class)->handle($me, SearchCriteria::fromInput([]));
    }

    expect(fn () => app(SearchProfiles::class)->handle($me, SearchCriteria::fromInput([])))->toThrow(SearchThrottled::class);
});
