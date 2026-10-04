<?php

declare(strict_types=1);

use App\Enums\AlertFrequency;
use App\Livewire\Member\Browse\AllProfiles;
use App\Models\Profile;
use App\Models\SavedSearch;
use App\Support\Navigation\ProfileBrowseList;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P2.2 — /profiles, All Profiles (M04): every visible profile with the four sorts and load more;
| the left rail manages the member's own saved searches (run, rename, alert, delete).
*/

beforeEach(function (): void {
    seedMasters();
});

function browseBride(array $attributes = []): Profile
{
    return Profile::factory()->female()->active()->create($attributes);
}

it('needs a signed-in, onboarded member and lives at /profiles', function (): void {
    $this->get(memberUrl('/profiles'))->assertRedirect(route('login'));

    $this->seed(PlansSeeder::class);
    $this->actingAs(groom(), 'web')->get(memberUrl('/profiles'))->assertOk()->assertSee('All Profiles');
});

it('lists visible profiles with a count; sort changes the order; never shows ids', function (): void {
    $older = browseBride(['published_at' => now()->subDay()]);
    $newer = browseBride(['published_at' => now()]);
    $this->actingAs(groom(), 'web');

    $component = Livewire::test(AllProfiles::class)->assertSee('2 profiles')->assertDontSee((string) $newer->id);
    $component->set('sort', 'newest');

    expect(array_column($component->get('results'), 'code'))->toBe([$newer->code, $older->code]);
});

it('loads 20 at a time and remembers the shown codes for Prev / Next (M03)', function (): void {
    foreach (range(1, 22) as $i) {
        browseBride();
    }
    $this->actingAs(groom(), 'web');

    $component = Livewire::test(AllProfiles::class)->assertCount('results', 20);
    $codes = array_column($component->call('loadMore')->assertCount('results', 22)->get('results'), 'code');

    expect(app(ProfileBrowseList::class)->neighbours($codes[20]))->toBe(['previous' => $codes[19], 'next' => $codes[21]]);
});

it('shows the member\'s saved searches with a run link built from normalised filters', function (): void {
    $member = groom();
    SavedSearch::factory()->create(['profile_id' => $member->profile->id, 'name' => 'Tall brides', 'filters' => ['height_min' => 170]]);
    SavedSearch::factory()->create(['profile_id' => bride()->profile->id, 'name' => 'Someone else\'s']);
    $this->actingAs($member, 'web');

    Livewire::test(AllProfiles::class)
        ->assertSee('Tall brides')->assertDontSee('Someone else')
        ->assertSee('(1/10)')
        ->assertSeeHtml(e(route('member.search', ['f' => ['height_min' => 170]])));
});

it('changes the alert frequency, renames and deletes the member\'s own saved search', function (): void {
    $member = groom();
    $saved = SavedSearch::factory()->create(['profile_id' => $member->profile->id, 'name' => 'Old name']);
    $this->actingAs($member, 'web');

    $component = Livewire::test(AllProfiles::class)
        ->call('updateFrequency', $saved->id, 'WEEKLY')->assertSee('Alert setting saved.');
    expect($saved->refresh()->alert_frequency)->toBe(AlertFrequency::Weekly);

    $component->call('startRename', $saved->id)->assertDispatched('open-modal', name: 'rename-search')
        ->assertSet('renameTo', 'Old name')
        ->set('renameTo', '')->call('rename')->assertHasErrors('renameTo')
        ->set('renameTo', 'New name')->call('rename')->assertHasNoErrors()->assertDispatched('close-modal', name: 'rename-search');
    expect($saved->refresh()->name)->toBe('New name');

    $component->call('deleteSavedSearch', $saved->id)->assertSee('Saved search deleted.');
    expect(SavedSearch::query()->count())->toBe(0);
});

it('IDOR: actions on another member\'s saved search are a 404 and change nothing', function (string $method): void {
    $theirs = SavedSearch::factory()->create(['profile_id' => bride()->profile->id, 'name' => 'Theirs']);
    $this->actingAs(groom(), 'web');

    $args = $method === 'updateFrequency' ? [$theirs->id, 'OFF'] : [$theirs->id];
    expect(fn () => Livewire::test(AllProfiles::class)->call($method, ...$args))->toThrow(ModelNotFoundException::class);
    expect($theirs->refresh()->name)->toBe('Theirs')->and($theirs->alert_frequency)->toBe(AlertFrequency::Daily);
})->with(['updateFrequency', 'startRename', 'deleteSavedSearch']);

it('an unknown alert frequency changes nothing', function (): void {
    $member = groom();
    $saved = SavedSearch::factory()->create(['profile_id' => $member->profile->id]);
    $this->actingAs($member, 'web');

    Livewire::test(AllProfiles::class)->call('updateFrequency', $saved->id, 'HOURLY')->assertDontSee('Alert setting saved.');
    expect($saved->refresh()->alert_frequency)->toBe(AlertFrequency::Daily);
});

it('the rename target, results, cursor and total can\'t be tampered with', function (string $property): void {
    $this->actingAs(groom(), 'web');

    expect(fn () => Livewire::test(AllProfiles::class)->set($property, $property === 'total' ? 9 : 'x'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['renamingId', 'results', 'cursor', 'total']);

it('shows the empty states', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(AllProfiles::class)->assertSee('No profiles yet')->assertSee('No saved searches yet');
});
