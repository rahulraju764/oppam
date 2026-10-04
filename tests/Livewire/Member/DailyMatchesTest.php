<?php

declare(strict_types=1);

use App\Livewire\Member\Matches\Daily;
use App\Models\Block;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| P2.4 — /matches/daily (M05 / F06): today's batch only, best first, read-only on load, with the
| expiry countdown; "Not interested" by profile code within the member's own batch.
*/

beforeEach(function (): void {
    seedMasters();
});

/** @return array{0: User, 1: Profile, 2: Profile} */
function dailySetup(): array
{
    $me = groom();
    $best = Profile::factory()->female()->active()->create();
    $next = Profile::factory()->female()->active()->create();
    foreach ([[$best, 95], [$next, 70]] as [$p, $score]) {
        DailyMatch::factory()->create(['profile_id' => $me->profile->id, 'matched_profile_id' => $p->id, 'score' => $score]);
    }

    return [$me, $best, $next];
}

it('needs a signed-in, onboarded member; /daily-matches redirects to /matches/daily', function (): void {
    $this->get(memberUrl('/matches/daily'))->assertRedirect(route('login'));
    $this->get(memberUrl('/daily-matches'))->assertRedirect('/matches/daily')->assertStatus(301);

    $this->seed(PlansSeeder::class);
    $this->actingAs(groom(), 'web')->get(memberUrl('/matches/daily'))->assertOk()->assertSee('Daily Matches');
});

it('shows today\'s batch best first with the expiry countdown, and writes nothing on load', function (): void {
    [$me, $best, $next] = dailySetup();
    $yesterday = Profile::factory()->female()->active()->create();
    DailyMatch::factory()->create(['profile_id' => $me->profile->id, 'matched_profile_id' => $yesterday->id, 'match_date' => now('Asia/Kolkata')->subDay()->toDateString()]);
    $this->actingAs($me, 'web');

    DB::enableQueryLog();
    $html = Livewire::test(Daily::class)->assertSee('expire in')->html();
    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => preg_match('/^\s*(insert|update|delete)/i', $q) === 1);

    expect(strpos($html, $best->code))->toBeLessThan(strpos($html, $next->code))
        ->and($html)->not->toContain($yesterday->code)
        ->and($writes)->toBeEmpty();
});

it('"Not interested" removes the profile from today\'s list by its code', function (): void {
    [$me, $best] = dailySetup();
    $this->actingAs($me, 'web');

    Livewire::test(Daily::class)->call('dismiss', $best->code)->assertDontSee($best->code)->assertSee('Removed from today');
    expect(DailyMatch::query()->where('matched_profile_id', $best->id)->value('is_interacted'))->toBeTrue();
});

it('IDOR: a code outside the member\'s own batch, or a junk code, changes nothing', function (): void {
    [$me, $best] = dailySetup();
    $someoneElse = bride();
    DailyMatch::factory()->create(['profile_id' => $someoneElse->profile->id, 'matched_profile_id' => $best->id]);
    $this->actingAs($me, 'web');

    Livewire::test(Daily::class)->call('dismiss', 'OPM99999999')->call('dismiss', "OPM1' OR 1=1");
    $this->actingAs($someoneElse, 'web');
    Livewire::test(Daily::class)->call('dismiss', $me->profile->code);

    expect(DailyMatch::query()->where('is_interacted', true)->count())->toBe(0);
});

it('R-M03-1: a profile that blocked the member after 05:00 drops out at once', function (): void {
    [$me, $best] = dailySetup();
    Block::factory()->create(['blocker_profile_id' => $best->id, 'blocked_profile_id' => $me->profile->id]);
    $this->actingAs($me, 'web');

    Livewire::test(Daily::class)->assertDontSee($best->code);
});

it('shows the empty state before the first batch', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(Daily::class)->assertSee('No daily matches right now')->assertSee('every morning at 5 AM');
});

it('P1.7b: an admin impersonating the member cannot dismiss a match', function (): void {
    [$me, $best] = dailySetup();
    $this->actingAs($me, 'web');
    session([App\Services\Admin\Impersonation::SESSION_KEY => 'imp-1']);

    Livewire::test(Daily::class)->call('dismiss', $best->code)->assertSee($best->code);
    expect(DailyMatch::query()->where('is_interacted', true)->exists())->toBeFalse();
});
