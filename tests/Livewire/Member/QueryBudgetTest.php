<?php

declare(strict_types=1);

use App\Livewire\Admin\Members\Index as AdminMembersIndex;
use App\Livewire\Member\Chat\Inbox;
use App\Livewire\Member\Dashboard\Strip;
use App\Livewire\Member\Notifications\Index as NotificationsIndex;
use App\Livewire\Member\Profile\Show;
use App\Livewire\Member\Search\Search;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Notifications\Member\DailyMatchesReady;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| P9.3c — query budgets for the heavy pages (PRD §14: TTFB < 400 ms p95, Livewire < 300 ms): each page is
| rendered with a FEW rows and again with MANY, and the second render may not run more queries — an N+1 (a
| query per row) fails here. Strict mode (no lazy loading) already fails a lazy load; this also catches an
| explicit query in a loop. The budget allows +k only where a page has a fixed extra per-section query.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
});

/** Queries run by one render (cache cleared first so both renders do the same work). */
function queryCount(callable $render): int
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $render();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('budget: member search — the results page runs no query per result', function (): void {
    $me = groom('+919811100001');
    $this->actingAs($me, 'web');
    Profile::factory()->count(2)->female()->active()->create();
    $render = fn () => Livewire::test(Search::class)->assertOk();
    $render();   // warm-up: masters / settings the first render reads once

    $few = queryCount($render);
    Profile::factory()->count(18)->female()->active()->create();

    expect(queryCount($render))->toBeLessThanOrEqual($few);
});

it('budget: profile page — the similar-profiles strip runs no query per profile', function (): void {
    $me = groom('+919811100002');
    $target = Profile::factory()->female()->active()->create();
    $this->actingAs($me, 'web');
    // Start with the strip already showing: its eager loads (career, privacy, one photo) are a fixed 3 queries
    // for the whole strip, not per profile — growing it must add none.
    Profile::factory()->count(3)->female()->active()->create(['religion_id' => $target->religion_id, 'dob' => $target->dob]);
    $render = fn () => Livewire::test(Show::class, ['profile' => $target->code])->assertOk();
    $render();

    $few = queryCount($render);
    Profile::factory()->count(8)->female()->active()->create(['religion_id' => $target->religion_id, 'dob' => $target->dob]);

    expect(queryCount($render))->toBeLessThanOrEqual($few);
});

it('budget: chat inbox — no query per conversation', function (): void {
    $me = chatOnPlan(groom('+919811100003'), App\Enums\PlanCode::Gold);
    $this->actingAs($me, 'web');
    $connect = function (int $from, int $count) use ($me): void {
        foreach (range($from, $from + $count - 1) as $i) {
            [, $her] = chatPairOf($me, bride('+9198112'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)));
            chatSend($me, $her, 'Hello '.$i);   // the Gold member writes (a Free member can't open a thread)
        }
    };
    $connect(1, 2);
    $render = fn () => Livewire::test(Inbox::class)->assertOk();
    $render();

    $few = queryCount($render);
    $connect(3, 8);

    expect(queryCount($render))->toBeLessThanOrEqual($few);
});

it('budget: notifications page — no query per notification', function (): void {
    $me = groom('+919811100004');
    $this->actingAs($me, 'web');
    $me->notify(new DailyMatchesReady(1));
    $me->notify(new DailyMatchesReady(2));
    $render = fn () => Livewire::test(NotificationsIndex::class)->assertOk();
    $render();

    $few = queryCount($render);
    foreach (range(3, 18) as $n) {
        $me->notify(new DailyMatchesReady($n));
    }

    expect(queryCount($render))->toBeLessThanOrEqual($few);
});

it('budget: dashboard daily strip — no query per recommended profile', function (): void {
    $me = groom('+919811100005');
    $this->actingAs($me, 'web');
    $match = fn (int $n) => DailyMatch::factory()->count($n)->create(['profile_id' => $me->profile->id,
        'matched_profile_id' => fn () => Profile::factory()->female()->active()->create()->id]);
    $match(2);
    $render = fn () => Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'daily'])->assertOk();
    $render();

    $few = queryCount($render);
    $match(8);

    expect(queryCount($render))->toBeLessThanOrEqual($few);
});

it('budget: A03 admin members list — no query per member row', function (): void {
    seedAdminRoles();
    $this->actingAs(adminWithRole('moderator'), 'admin');
    foreach (range(1, 2) as $i) {
        memberWithPhone('+9198113'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
    }
    $render = fn () => Livewire::test(AdminMembersIndex::class)->assertOk();
    $render();

    $few = queryCount($render);
    foreach (range(3, 20) as $i) {
        memberWithPhone('+9198113'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
    }

    expect(queryCount($render))->toBeLessThanOrEqual($few);
});
