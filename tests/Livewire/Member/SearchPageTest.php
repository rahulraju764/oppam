<?php

declare(strict_types=1);

use App\Livewire\Member\Search\Search;
use App\Models\Block;
use App\Models\Profile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P2.1 — the /search page (M04): results + live count, filters in the URL, dependent fields,
| load more, find by ID, locked state, empty / loading states; guests sent to sign in.
*/

beforeEach(function (): void {
    seedMasters();
});

function searchBride(array $attributes = []): Profile
{
    return Profile::factory()->female()->active()->create($attributes);
}

it('shows results with a live count, as cards that carry codes and never database ids', function (): void {
    $me = groom('+919888800001');
    $bride = searchBride();
    $this->actingAs($me, 'web');

    Livewire::test(Search::class)->assertOk()
        ->assertSee($bride->code)->assertSee('1 profile')
        ->assertDontSee((string) $bride->id)
        ->assertSeeHtml('wire:loading');
});

it('M04 acceptance: the URL reproduces the search (filters come from ?f[...])', function (): void {
    $me = groom('+919888800002');
    $match = searchBride(['height_cm' => 171]);
    $other = searchBride(['height_cm' => 150]);
    $this->actingAs($me, 'web');

    Livewire::withQueryParams(['f' => ['height_min' => '165']])->test(Search::class)
        ->assertSet('filters.height_min', '165')
        ->assertSee($match->code)->assertDontSee($other->code);
});

it('changing a filter updates the results; a new religion clears the castes chosen under the old one', function (): void {
    $me = groom('+919888800003');
    $bride = searchBride();
    $this->actingAs($me, 'web');

    Livewire::test(Search::class)
        ->set('filters.religion', (string) $bride->religion_id)
        ->set('filters.caste', [(string) $bride->caste_id])
        ->assertSee($bride->code)
        ->set('filters.religion', (string) App\Models\Masters\Religion::query()->where('id', '!=', $bride->religion_id)->value('id'))
        ->assertSet('filters.caste', null)
        ->assertDontSee($bride->code);
});

it('loads 20 at a time and appends the next page', function (): void {
    $me = groom('+919888800004');
    foreach (range(1, 25) as $i) {
        searchBride();
    }
    $this->actingAs($me, 'web');

    $component = Livewire::test(Search::class)->assertCount('results', 20)->assertSee('Load more');
    $component->call('loadMore')->assertCount('results', 25)->assertDontSee('Load more');
});

it('find by ID opens a visible profile and gives one message for a missing or hidden one', function (): void {
    $me = groom('+919888800005');
    $visible = searchBride();
    $blocked = searchBride();
    Block::factory()->create(['blocker_profile_id' => $blocked->id, 'blocked_profile_id' => $me->profile->id]);
    $man = Profile::factory()->male()->active()->create();
    $this->actingAs($me, 'web');

    Livewire::test(Search::class)->set('profileId', strtolower($visible->code))->call('findById')
        ->assertRedirect(route('member.profile.show', ['profile' => $visible->code]));

    foreach (['OPM99999999', $blocked->code, $man->code, "OPM1' OR 1=1"] as $code) {
        Livewire::test(Search::class)->set('profileId', $code)->call('findById')
            ->assertNoRedirect()->assertSee('No profile with that ID');
    }
});

it('P2.1 review: find by ID is rate-limited (codes can\'t be probed in bulk)', function (): void {
    $this->actingAs(groom('+919888800009'), 'web');
    $component = Livewire::test(Search::class);

    foreach (range(1, App\Actions\Search\FindProfileById::MAX_PER_MINUTE) as $i) {
        $component->set('profileId', 'OPM9'.$i)->call('findById')->assertSee('No profile with that ID');
    }

    $component->set('profileId', 'OPM12370')->call('findById')->assertSee('You are searching very quickly');
});

it('the shown results and the paging cursor can\'t be tampered with', function (string $property): void {
    $this->actingAs(groom('+919888800006'), 'web');

    expect(fn () => Livewire::test(Search::class)->set($property, $property === 'total' ? 99 : 'x'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['results', 'cursor', 'total']);

it('shows the empty state when nothing matches', function (): void {
    $this->actingAs(groom('+919888800007'), 'web');

    Livewire::test(Search::class)->assertSee('No profiles match')->assertSee('0 profiles');
});

it('the search page needs a signed-in, onboarded member', function (): void {
    $this->get(memberUrl('/search'))->assertRedirect(route('login'));

    $this->seed(Database\Seeders\PlansSeeder::class);   // the member layout shows the plan
    $this->actingAs(groom('+919888800008'), 'web');
    $this->get(memberUrl('/search'))->assertOk()->assertSee('Search Results');
});
