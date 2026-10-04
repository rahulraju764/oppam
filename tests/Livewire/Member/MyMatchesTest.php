<?php

declare(strict_types=1);

use App\Domain\Matching\MatchFunnel;
use App\Livewire\Member\Matches\MyMatches;
use App\Models\Block;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Support\Navigation\ProfileBrowseList;
use Database\Seeders\PlansSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P2.3 — /matches, My Matches (M05): the funnel counters and tabs, results 20 at a time, the tab in
| the URL, blocked pairs never shown, tamper-proof state; guests to sign in.
*/

beforeEach(function (): void {
    seedMasters();
});

function matchBride(array $state = []): Profile
{
    return Profile::factory()->female()->active()->create($state);
}

it('needs a signed-in, onboarded member, lives at /matches, and /my-matches redirects there', function (): void {
    $this->get(memberUrl('/matches'))->assertRedirect(route('login'));
    $this->get(memberUrl('/my-matches'))->assertRedirect('/matches')->assertStatus(301);

    $this->seed(PlansSeeder::class);
    $this->actingAs(groom(), 'web')->get(memberUrl('/matches'))->assertOk()->assertSee('Your match funnel');
});

it('shows the 2 × 2 funnel and every tab with its count; never database ids', function (): void {
    $me = groom();
    $bride = matchBride();
    ProfileView::factory()->create(['viewer_profile_id' => $me->profile->id, 'viewed_profile_id' => $bride->id]);
    $this->actingAs($me, 'web');

    Livewire::test(MyMatches::class)
        ->assertSee(['All Matches', 'Yet to be Viewed', 'Viewed', 'Mutual Matches', 'New Matches', 'Near Me', 'Premium'])
        ->assertSee($bride->code)
        ->assertDontSee((string) $bride->id);
});

it('switching tabs changes the list and lands in the URL; an unknown tab falls back to All', function (): void {
    $me = groom();
    $seen = matchBride();
    $unseen = matchBride();
    ProfileView::factory()->create(['viewer_profile_id' => $me->profile->id, 'viewed_profile_id' => $seen->id]);
    $this->actingAs($me, 'web');

    $component = Livewire::test(MyMatches::class)->call('show', 'viewed')->assertSet('tab', 'viewed');
    expect(array_column($component->get('results'), 'code'))->toBe([$seen->code]);

    $component->call('show', 'unviewed');
    expect(array_column($component->get('results'), 'code'))->toBe([$unseen->code]);

    Livewire::withQueryParams(['tab' => 'nonsense'])->test(MyMatches::class)->assertSet('tab', 'all');
});

it('loads 20 at a time and feeds Prev / Next (M03)', function (): void {
    foreach (range(1, 21) as $i) {
        matchBride();
    }
    $this->actingAs(groom(), 'web');

    $codes = array_column(Livewire::test(MyMatches::class)->assertCount('results', 20)->call('loadMore')->assertCount('results', 21)->get('results'), 'code');

    expect(app(ProfileBrowseList::class)->neighbours($codes[20])['previous'])->toBe($codes[19]);
});

it('Near me without a district explains why it is empty', function (): void {
    $me = groom();
    $me->profile->forceFill(['district_id' => null])->save();
    $this->actingAs($me->refresh(), 'web');

    Livewire::test(MyMatches::class)->call('show', 'near')->assertSee('Add your district');
});

it('R-M03-1: a blocked pair is in no tab', function (): void {
    $me = groom();
    $blocked = matchBride();
    Block::factory()->create(['blocker_profile_id' => $blocked->id, 'blocked_profile_id' => $me->profile->id]);
    $this->actingAs($me, 'web');

    foreach (['all', 'unviewed', 'viewed', 'mutual', 'new', 'near', 'premium'] as $tab) {
        Livewire::test(MyMatches::class)->call('show', $tab)->assertDontSee($blocked->code);
    }
});

it('the counts come from the cache (not recomputed on every click)', function (): void {
    $me = groom();
    matchBride();
    $this->actingAs($me, 'web');

    $component = Livewire::test(MyMatches::class);
    matchBride();
    $component->call('show', 'all');

    expect(app(MatchFunnel::class)->counts($me->profile)['all'])->toBe(1);
});

it('switching tabs counts against search.max_per_minute; when throttled no stale cards are shown', function (): void {
    App\Models\Setting::factory()->keyed(App\Enums\SettingKey::SearchMaxPerMinute, 10)->create();
    matchBride();
    $this->actingAs(groom(), 'web');

    $component = Livewire::test(MyMatches::class);   // 1
    foreach (range(1, 9) as $i) {
        $component->call('show', 'all');
    }
    $component->call('show', 'premium')->assertSee('You are searching very quickly')->assertSet('results', []);
});

it('results and cursor can\'t be tampered with', function (string $property): void {
    $this->actingAs(groom(), 'web');

    expect(fn () => Livewire::test(MyMatches::class)->set($property, 'x'))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['results', 'cursor']);

it('shows the empty state', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(MyMatches::class)->assertSee('No matches here yet');
});
