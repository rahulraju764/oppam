<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Livewire\Member\Dashboard\Strip;
use App\Livewire\Member\Engagement\Favorites;
use App\Livewire\Member\Engagement\Likes;
use App\Livewire\Member\Profile\Show;
use App\Livewire\Shared\FavoriteButton;
use App\Livewire\Shared\LikeButton;
use App\Models\Block;
use App\Models\Favorite;
use App\Models\Like;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P3.3 — the Like / Favorite buttons (optimistic in Alpine, the server decides), /likes
| (liked me — count only for Free — / I liked / mutual, "new" marks), /favorites (private notes,
| remove several), the dashboard "Liked you" strip and the buttons on the profile page and rows.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
});

function egGold(User $member): User
{
    Subscription::factory()->create(['profile_id' => $member->profile->id, 'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->sole()->id]);
    app(EntitlementService::class)->forget($member->profile);

    return $member;
}

function egTarget(array $state = []): Profile
{
    return Profile::factory()->female()->active()->create($state);
}

// ---- LikeButton -------------------------------------------------------------------------------

it('LikeButton: takes its state from the page (no query) or looks it up itself', function (): void {
    $me = groom();
    $liked = egTarget();
    Like::factory()->between($me->profile, $liked)->create();
    $this->actingAs($me, 'web');

    DB::enableQueryLog();
    Livewire::test(LikeButton::class, ['profileCode' => $liked->code, 'liked' => true, 'name' => 'Anna T.'])
        ->assertSet('liked', true)->assertSee('Liked')->assertSeeHtml('aria-pressed="true"')->assertSeeHtml('aria-label="Like Anna T."');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, '`likes`')))->toHaveCount(0);

    Livewire::test(LikeButton::class, ['profileCode' => $liked->code])->assertSet('liked', true);
    Livewire::test(LikeButton::class, ['profileCode' => egTarget()->code])->assertSet('liked', false)
        ->assertSeeHtml('aria-pressed="false"')
        ->assertSeeHtml('x-bind:aria-disabled="busy.toString()"');   // busy while its own request runs (keeps focus)
});

it('LikeButton: like and un-like go through ToggleLike and tell the other buttons for that profile', function (): void {
    $me = groom();
    $target = egTarget();
    $this->actingAs($me, 'web');

    Livewire::test(LikeButton::class, ['profileCode' => $target->code, 'liked' => false])
        ->call('like', true)->assertSet('liked', true)->assertDispatched('like-changed', code: $target->code, on: true)
        ->assertNotDispatched('its-a-match');
    expect(Like::query()->where('from_profile_id', $me->profile->id)->where('to_profile_id', $target->id)->exists())->toBeTrue();

    Livewire::test(LikeButton::class, ['profileCode' => $target->code, 'liked' => true])
        ->call('like', false)->assertSet('liked', false)->assertDispatched('like-changed', code: $target->code, on: false);
    expect(Like::query()->count())->toBe(0);
});

it('M06 acceptance: a mutual like shows "It\'s a match!" to the second liker immediately', function (): void {
    $me = groom();
    $bride = bride();
    Like::factory()->between($bride->profile, $me->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::test(LikeButton::class, ['profileCode' => $bride->profile->code, 'liked' => false])
        ->call('like', true)
        ->assertDispatched('its-a-match', fn (string $event, array $params): bool => $params['url'] === route('member.profile.show', ['profile' => $bride->profile->code])
            && is_string($params['name']) && $params['name'] !== '' && ! str_contains(json_encode($params), $bride->profile->id));
});

it('M06 acceptance: at the daily limit the upgrade dialog opens and the server refuses — the button stays off', function (): void {
    $me = groom();
    foreach (range(1, 10) as $i) {
        Livewire::actingAs($me, 'web')->test(LikeButton::class, ['profileCode' => egTarget()->code, 'liked' => false])->call('like', true)->assertSet('liked', true);
    }
    $eleventh = egTarget();

    Livewire::actingAs($me, 'web')->test(LikeButton::class, ['profileCode' => $eleventh->code, 'liked' => false])
        ->call('like', true)
        ->assertSet('liked', false)
        ->assertDispatched('quota-reached')
        ->assertNotDispatched('like-changed');

    expect(Like::query()->where('to_profile_id', $eleventh->id)->exists())->toBeFalse();
});

it('LikeButton: a refusal becomes a toast and nothing is written', function (string $case): void {
    $me = groom();
    $target = egTarget();
    match ($case) {
        'blocked pair' => Block::factory()->create(['blocker_profile_id' => $target->id, 'blocked_profile_id' => $me->profile->id]),
        'target suspended' => $target->forceFill(['status' => ProfileStatus::Suspended])->save(),
        'own profile pending' => $me->profile->forceFill(['status' => ProfileStatus::PendingReview])->save(),
        'support session' => null,
    };
    $this->actingAs($me->refresh(), 'web');
    if ($case === 'support session') {
        session([Impersonation::SESSION_KEY => 'imp-1']);
    }

    Livewire::test(LikeButton::class, ['profileCode' => $target->code, 'liked' => false])
        ->call('like', true)->assertSet('liked', false)->assertDispatched('toast')->assertNotDispatched('like-changed');

    expect(Like::query()->count())->toBe(0);
})->with(['blocked pair', 'target suspended', 'own profile pending', 'support session']);

it('LikeButton: a code that is no profile changes nothing', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(LikeButton::class, ['profileCode' => 'OPM99999999', 'liked' => false])
        ->call('like', true)->assertSet('liked', false)->assertDispatched('toast');
    expect(Like::query()->count())->toBe(0);
});

it('the buttons\' profile code, state, variant and name can\'t be tampered with', function (string $component, string $property): void {
    $this->actingAs(groom(), 'web');
    $state = $component === LikeButton::class ? 'liked' : 'favorited';

    expect(fn () => Livewire::test($component, ['profileCode' => egTarget()->code, $state => false])
        ->set($property, in_array($property, ['liked', 'favorited'], true) ? true : 'OPM1'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    [LikeButton::class, 'profileCode'], [LikeButton::class, 'liked'], [LikeButton::class, 'variant'], [LikeButton::class, 'name'],
    [FavoriteButton::class, 'profileCode'], [FavoriteButton::class, 'favorited'], [FavoriteButton::class, 'variant'], [FavoriteButton::class, 'name'],
]);

// ---- FavoriteButton ---------------------------------------------------------------------------

it('FavoriteButton: bookmark and remove go through ToggleFavorite; the full plan opens the upgrade dialog', function (): void {
    $me = groom();
    $target = egTarget();
    $this->actingAs($me, 'web');

    Livewire::test(FavoriteButton::class, ['profileCode' => $target->code, 'favorited' => false, 'name' => 'Anna T.'])
        ->assertSeeHtml('aria-label="Favourite Anna T."')->assertSeeHtml('aria-pressed="false"')->assertSeeHtml('x-bind:aria-disabled="busy.toString()"')
        ->call('favorite', true)->assertSet('favorited', true)->assertDispatched('favorite-changed', code: $target->code, on: true);
    expect(Favorite::query()->where('profile_id', $me->profile->id)->count())->toBe(1);

    Livewire::test(FavoriteButton::class, ['profileCode' => $target->code])->assertSet('favorited', true)
        ->call('favorite', false)->assertSet('favorited', false);
    expect(Favorite::query()->count())->toBe(0);

    foreach (range(1, 25) as $i) {
        Favorite::factory()->between($me->profile, egTarget())->create();
    }
    DB::table('entitlement_usages')->where('profile_id', $me->profile->id)->where('entitlement', 'favorites_total')->update(['used' => 25]);

    Livewire::test(FavoriteButton::class, ['profileCode' => egTarget()->code, 'favorited' => false])
        ->call('favorite', true)->assertSet('favorited', false)->assertDispatched('quota-reached');
    expect(Favorite::query()->count())->toBe(25);
});

it('FavoriteButton: a blocked pair, a missing profile or a support session change nothing', function (string $case): void {
    $me = groom();
    $target = egTarget();
    if ($case === 'blocked pair') {
        Block::factory()->create(['blocker_profile_id' => $me->profile->id, 'blocked_profile_id' => $target->id]);
    }
    $this->actingAs($me, 'web');
    if ($case === 'support session') {
        session([Impersonation::SESSION_KEY => 'imp-1']);
    }

    Livewire::test(FavoriteButton::class, ['profileCode' => $case === 'missing profile' ? 'OPM99999999' : $target->code, 'favorited' => false])
        ->call('favorite', true)->assertSet('favorited', false)->assertDispatched('toast')->assertNotDispatched('favorite-changed');

    expect(Favorite::query()->count())->toBe(0);
})->with(['blocked pair', 'missing profile', 'support session']);

// ---- /likes -----------------------------------------------------------------------------------

it('/likes needs a signed-in, onboarded member', function (): void {
    $this->get(memberUrl('/likes'))->assertRedirect(route('login'));
    $this->get(memberUrl('/favorites'))->assertRedirect(route('login'));

    $this->actingAs(groom(), 'web');
    $this->get(memberUrl('/likes'))->assertOk()->assertSee('Liked me');
    $this->get(memberUrl('/favorites'))->assertOk()->assertSee('Only you can see this list.');
});

it('M06 / §7.3: a Free member sees only how many liked them, with an upgrade link — no liker data, nothing marked seen', function (): void {
    $me = groom();
    $liker = egTarget();
    Like::factory()->between($liker, $me->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::test(Likes::class)
        ->assertSee('1 member liked your profile')->assertSee('See plans')
        ->assertDontSee($liker->code)->assertDontSee($liker->first_name)
        ->assertSet('newCodes', []);

    expect(Like::query()->whereNull('seen_at')->count())->toBe(1);
});

it('M06: Gold sees who liked them, newest first, new ones marked once — then they are seen', function (): void {
    $me = egGold(groom());
    $older = egTarget();
    $newer = egTarget();
    Like::factory()->between($older, $me->profile)->seen()->create(['created_at' => now()->subDays(3)]);
    Like::factory()->between($newer, $me->profile)->create(['created_at' => now()->subHour()]);
    $this->actingAs($me, 'web');

    Livewire::test(Likes::class)
        ->assertSeeInOrder([$newer->code, $older->code])
        ->assertSet('newCodes', [$newer->code])
        ->assertSeeInOrder([$newer->code, 'New', 'Liked you'])
        ->assertSeeLivewire(LikeButton::class)->assertSeeLivewire(FavoriteButton::class);

    expect(Like::query()->whereNull('seen_at')->count())->toBe(0);
    Livewire::test(Likes::class)->assertSet('newCodes', [])->assertSee($newer->code);   // next visit: no "new" mark
});

it('/likes tabs: I liked and Mutual for every plan, with counts; an unknown tab falls back', function (): void {
    $me = groom();
    $liked = egTarget();
    $mutual = egTarget();
    Like::factory()->between($me->profile, $liked)->create();
    Like::factory()->between($me->profile, $mutual)->create();
    Like::factory()->between($mutual, $me->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::withQueryParams(['tab' => 'liked'])->test(Likes::class)
        ->assertSee($liked->code)->assertSee($mutual->code)->assertSee('You liked')
        ->call('show', 'mutual')->assertSet('tab', 'mutual')->assertSee($mutual->code)->assertDontSee($liked->code)
        ->call('show', 'nonsense')->assertSet('tab', 'liked-me');

    Livewire::withQueryParams(['tab' => '<script>'])->test(Likes::class)->assertSet('tab', 'liked-me');
});

it('/likes: empty states, and it listens for a live like, a like made on the page and reconnects', function (): void {
    $me = egGold(groom());
    $this->actingAs($me, 'web');

    $page = Livewire::test(Likes::class)->assertSee('No likes yet')
        ->call('show', 'liked')->assertSee('You haven’t liked anyone yet')
        ->call('show', 'mutual')->assertSee('No mutual likes yet');

    expect($page->instance()->getListeners())
        ->toHaveKey('echo-private:App.Models.User.'.$me->id.',.like.changed')
        ->toHaveKey('realtime-reconnected')->toHaveKey('like-changed');
});

it('M06 acceptance: a like that arrives while the page is open appears on refresh, marked new', function (): void {
    $me = egGold(groom());
    $this->actingAs($me, 'web');
    $page = Livewire::test(Likes::class)->assertSee('No likes yet');

    $liker = egTarget();
    Like::factory()->between($liker, $me->profile)->create();

    $page->call('refreshLikes')->assertSee($liker->code)->assertSet('newCodes', [$liker->code]);
});

it('/likes: blocked members are never listed, and the "new" list can\'t be tampered with', function (): void {
    $me = egGold(groom());
    $blocked = egTarget();
    Like::factory()->between($blocked, $me->profile)->create();
    Block::factory()->create(['blocker_profile_id' => $me->profile->id, 'blocked_profile_id' => $blocked->id]);
    $this->actingAs($me, 'web');

    Livewire::test(Likes::class)->assertDontSee($blocked->code)->assertSee('No likes yet');
    expect(fn () => Livewire::test(Likes::class)->set('newCodes', ['OPM1']))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('/likes paginates 20 a page', function (): void {
    $me = egGold(groom());
    foreach (range(1, 21) as $i) {
        Like::factory()->between(egTarget(), $me->profile)->create(['created_at' => now()->subMinutes($i)]);
    }
    $oldest = Profile::query()->findOrFail(Like::query()->oldest()->value('from_profile_id'));
    $this->actingAs($me, 'web');

    Livewire::test(Likes::class)->assertDontSee($oldest->code)->call('gotoPage', 2)->assertSee($oldest->code);
});

// ---- /favorites -------------------------------------------------------------------------------

it('/favorites: the member\'s own bookmarks with their notes and the plan\'s count', function (): void {
    $me = groom();
    $mine = egTarget();
    $theirs = egTarget();
    app(App\Actions\Engagement\ToggleFavorite::class)->handle($me, $mine, true);
    app(App\Actions\Engagement\SaveFavoriteNote::class)->handle($me, $mine->code, 'Met the family');
    Favorite::factory()->between(groom('+919800000086')->profile, $theirs)->create(['note' => 'not mine']);
    $this->actingAs($me, 'web');

    Livewire::test(Favorites::class)
        ->assertSee($mine->code)->assertSee('Met the family')->assertSee('1 of 25 used')
        ->assertDontSee($theirs->code)->assertDontSee('not mine')
        ->assertSeeLivewire(FavoriteButton::class);
});

it('/favorites: saving a note — own only, validated, escaped', function (): void {
    $me = groom();
    $mine = egTarget();
    $theirs = egTarget();
    Favorite::factory()->between($me->profile, $mine)->create();
    Favorite::factory()->between(groom('+919800000085')->profile, $theirs)->create(['note' => 'theirs']);
    $this->actingAs($me, 'web');

    Livewire::test(Favorites::class)
        ->call('saveNote', $mine->code, '<script>alert(1)</script> nice family')->assertDispatched('toast')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->call('saveNote', $mine->code, str_repeat('x', 201))->assertHasErrors('note.'.$mine->code)
        ->call('saveNote', $theirs->code, 'hijack')->assertHasNoErrors();

    expect(Favorite::query()->where('profile_id', $me->profile->id)->value('note'))->toBe('<script>alert(1)</script> nice family')
        ->and(Favorite::query()->where('favorite_profile_id', $theirs->id)->value('note'))->toBe('theirs');
});

it('/favorites: remove selected removes only ticked favourites of the member; the list and count follow', function (): void {
    $me = groom();
    [$a, $b] = [egTarget(), egTarget()];
    $others = egTarget();
    app(App\Actions\Engagement\ToggleFavorite::class)->handle($me, $a, true);
    app(App\Actions\Engagement\ToggleFavorite::class)->handle($me, $b, true);
    Favorite::factory()->between(groom('+919800000084')->profile, $others)->create();
    $this->actingAs($me, 'web');

    Livewire::test(Favorites::class)
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="removeSelected"')
        ->set('selected', [$a->code, $others->code, 'junk'])
        ->call('removeSelected')
        ->assertSet('selected', [])->assertDispatched('toast')
        ->assertDontSee($a->code)->assertSee($b->code)->assertSee('1 of 25 used');

    expect(Favorite::query()->count())->toBe(2);
});

it('/favorites: empty state; a support session changes nothing', function (): void {
    $me = groom();
    $this->actingAs($me, 'web');
    Livewire::test(Favorites::class)->assertSee('No favourites yet')->assertSee('Nobody is told.');

    $mine = egTarget();
    Favorite::factory()->between($me->profile, $mine)->create(['note' => 'keep']);
    session([Impersonation::SESSION_KEY => 'imp-1']);

    Livewire::test(Favorites::class)
        ->call('saveNote', $mine->code, 'changed')->assertDispatched('toast')
        ->set('selected', [$mine->code])->call('removeSelected')
        ->call('removeUnavailable');
    expect(Favorite::query()->sole()->note)->toBe('keep');
});

it('R-M06-6: an admin support session sees only HOW MANY favourites — never whom, never the notes, no star on any card', function (): void {
    $me = groom();
    $mine = egTarget();
    app(App\Actions\Engagement\ToggleFavorite::class)->handle($me, $mine, true);
    app(App\Actions\Engagement\SaveFavoriteNote::class)->handle($me, $mine->code, 'Met the family');
    Like::factory()->between($mine, $me->profile)->create();
    egGold($me);
    $this->actingAs($me, 'web');
    session([Impersonation::SESSION_KEY => 'imp-1']);

    Livewire::test(Favorites::class)
        ->assertSee('1 favourite')->assertSee('not shown during a support session')
        ->assertDontSee($mine->code)->assertDontSee($mine->first_name)->assertDontSee('Met the family')
        ->assertDontSeeLivewire(FavoriteButton::class);

    // A list that shows the bookmarked profile for another reason carries no star for it…
    Livewire::test(Likes::class)->assertSee($mine->code)->assertDontSeeLivewire(FavoriteButton::class);
    // …and the button on her profile page shows "not saved".
    Livewire::test(FavoriteButton::class, ['profileCode' => $mine->code])->assertSet('favorited', false);
});

it('§7.3: favourites whose profile is no longer available can be cleared, and their slots come back', function (): void {
    $me = groom();
    [$visible, $hidden, $suspended, $blocked] = [egTarget(), egTarget(), egTarget(), egTarget()];
    foreach ([$visible, $hidden, $suspended, $blocked] as $target) {
        app(App\Actions\Engagement\ToggleFavorite::class)->handle($me, $target, true);
    }
    $othersHidden = egTarget();
    Favorite::factory()->between(groom('+919800000083')->profile, $othersHidden)->create();
    $hidden->forceFill(['status' => ProfileStatus::Hidden])->save();
    $suspended->forceFill(['status' => ProfileStatus::Suspended])->save();
    $othersHidden->forceFill(['status' => ProfileStatus::Hidden])->save();
    Block::factory()->create(['blocker_profile_id' => $blocked->id, 'blocked_profile_id' => $me->profile->id]);
    $this->actingAs($me, 'web');

    Livewire::test(Favorites::class)
        ->assertSee('4 of 25 used')->assertSee('3 saved profiles are no longer available')
        ->assertSee($visible->code)->assertDontSee($hidden->code)->assertDontSee($blocked->code)
        ->call('removeUnavailable')->assertDispatched('toast')
        ->assertSee('1 of 25 used')->assertDontSee('no longer available')->assertSee($visible->code);

    expect(Favorite::query()->where('profile_id', $me->profile->id)->pluck('favorite_profile_id')->all())->toBe([$visible->id])
        ->and(Favorite::query()->where('favorite_profile_id', $othersHidden->id)->count())->toBe(1);   // someone else's stays
});

it('/favorites and /likes: when the last row of page 2 goes, the member is taken back to page 1', function (): void {
    $me = groom();
    $targets = collect(range(1, 21))->map(fn (int $i): Profile => tap(egTarget(), function (Profile $t) use ($me, $i): void {
        Favorite::factory()->between($me->profile, $t)->create(['created_at' => now()->subMinutes($i)]);
        Like::factory()->between($me->profile, $t)->create(['created_at' => now()->subMinutes($i)]);
    }));
    $oldest = $targets->last();
    $this->actingAs($me, 'web');

    $favorites = Livewire::test(Favorites::class)->call('gotoPage', 2)->assertSee($oldest->code);
    app(App\Actions\Engagement\RemoveFavorites::class)->handle($me, [$oldest->code]);
    $favorites->call('favoriteChanged', $oldest->code)->assertDontSee('No favourites yet')->assertSee($targets->first()->code);

    $likes = Livewire::withQueryParams(['tab' => 'liked'])->test(Likes::class)->call('gotoPage', 2)->assertSee($oldest->code);
    app(App\Actions\Engagement\ToggleLike::class)->handle($me, $oldest, false);
    $likes->call('$refresh')->assertDontSee('You haven’t liked anyone yet')->assertSee($targets->first()->code);
});

it('/favorites: a row whose star was just cleared no longer counts as ticked', function (): void {
    $me = groom();
    [$a, $b] = [egTarget(), egTarget()];
    Favorite::factory()->between($me->profile, $a)->create();
    Favorite::factory()->between($me->profile, $b)->create();
    $this->actingAs($me, 'web');

    Livewire::test(Favorites::class)->set('selected', [$a->code, $b->code])
        ->call('favoriteChanged', $a->code)->assertSet('selected', [$b->code]);
});

it('the buttons need a signed-in member', function (string $component): void {
    Livewire::test($component, ['profileCode' => egTarget()->code])->assertNotFound();
})->with(['like' => [LikeButton::class], 'favourite' => [FavoriteButton::class]]);

// ---- Around the site --------------------------------------------------------------------------

it('the profile page has live Like and Favorite buttons in place of the placeholders', function (): void {
    $me = groom();
    $bride = bride();
    $this->actingAs($me, 'web');

    Livewire::test(Show::class, ['profile' => $bride->profile->code])
        ->assertSeeLivewire(LikeButton::class)->assertSeeLivewire(FavoriteButton::class)
        ->assertSee('Send Interest');
});

it('a member\'s own profile page has no like or favourite button', function (): void {
    $me = groom();
    $this->actingAs($me, 'web');

    Livewire::test(Show::class, ['profile' => $me->profile->code])
        ->assertDontSeeLivewire(LikeButton::class)->assertDontSeeLivewire(FavoriteButton::class);
});

it('M05 dashboard "Liked you": the number behind an upgrade prompt for Free, the members for Gold — live on a new like', function (): void {
    $me = groom();
    $liker = egTarget();
    Like::factory()->between($liker, $me->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'liked'])
        ->assertSee('Liked You')->assertSee('1 member liked your profile')->assertSee('Upgrade to see who')
        ->assertDontSee($liker->first_name);

    egGold($me);
    $strip = Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'liked'])
        ->assertSee($liker->first_name)->assertSeeLivewire(LikeButton::class);

    expect($strip->instance()->getListeners())->toHaveKey('echo-private:App.Models.User.'.$me->id.',.like.changed')
        ->and(Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'new'])->instance()->getListeners())->toBe([]);
});

it('list cards carry the viewer\'s like / bookmark state, so a page of rows needs no query per button', function (): void {
    $me = egGold(groom());
    foreach (range(1, 6) as $i) {
        $liker = egTarget();
        Like::factory()->between($liker, $me->profile)->create();
        if ($i % 2 === 0) {
            Like::factory()->between($me->profile, $liker)->create();
        }
    }
    $this->actingAs($me, 'web');

    DB::enableQueryLog();
    Livewire::test(Likes::class)->assertSeeHtml('aria-pressed="true"')->assertSeeHtml('aria-pressed="false"');
    $perButton = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $q): bool => str_contains($q, 'from `likes` where `from_profile_id`') && str_contains($q, 'exists'));

    expect($perButton)->toHaveCount(0);
});
