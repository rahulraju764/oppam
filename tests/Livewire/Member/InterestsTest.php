<?php

declare(strict_types=1);

use App\Enums\AccessRequestStatus;
use App\Enums\AccessRequestType;
use App\Enums\InterestStatus;
use App\Enums\PhotoVisibility;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Livewire\Member\Engagement\Favorites;
use App\Livewire\Member\Engagement\Interests;
use App\Livewire\Member\Engagement\Likes;
use App\Livewire\Member\Profile\Show;
use App\Livewire\Shared\InterestComposer;
use App\Models\AccessRequest;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Favorite;
use App\Models\Interest;
use App\Models\Like;
use App\Models\PartnerPreference;
use App\Models\Plan;
use App\Models\PrivacySetting;
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
| P3.4 — the Send Interest dialog (ready messages, own note on paid plans, remaining quota, upgrade
| dialog), /interests (received / sent by status, accept / decline / withdraw, decline selected,
| photo & horoscope requests), and the interest → chat button on the profile page and rows.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
});

function ivGold(User $member): User
{
    Subscription::factory()->create(['profile_id' => $member->profile->id, 'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->sole()->id]);
    app(EntitlementService::class)->forget($member->profile);

    return $member;
}

function ivTarget(array $state = []): Profile
{
    return Profile::factory()->female()->active()->create($state);
}

function ivOnRequest(Profile $profile): Profile
{
    $privacy = PrivacySetting::query()->whereKey($profile->id)->first() ?? new PrivacySetting;
    $privacy->forceFill(['profile_id' => $profile->id, 'photo_visibility' => PhotoVisibility::OnRequest])->save();

    return $profile->refresh();
}

// ---- The Send Interest dialog ---------------------------------------------------------------------

it('the dialog loads nothing until it is opened, then shows the ready messages and what is left this month', function (): void {
    $me = groom();
    $target = ivTarget(['first_name' => 'Anjali', 'last_name' => 'Nair']);
    $this->actingAs($me, 'web');

    DB::enableQueryLog();
    $dialog = Livewire::test(InterestComposer::class)->assertSet('code', '')->assertSet('templates', []);
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'master_options') || str_contains($q, 'interests')))->toHaveCount(0);

    $dialog->dispatch('compose-interest', code: $target->code)
        ->assertSet('code', $target->code)->assertSet('name', 'Anjali N.')
        ->assertCount('templates', 5)->assertSet('template', 'T01')
        ->assertSet('remaining', 3)->assertSet('customAllowed', false)
        ->assertDispatched('open-modal', name: 'send-interest')
        ->assertSee('Send interest to Anjali N.')
        ->assertSee('I liked your profile and would like to know more about you.')
        ->assertSee('3 interests left this month')
        ->assertSee('Writing your own message is part of the paid plans.')
        ->assertDontSee('Or write your own')
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="send"');
});

it('a paid plan may write its own note; Gold sees its own allowance', function (): void {
    $me = ivGold(groom());
    $this->actingAs($me, 'web');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: ivTarget()->code)
        ->assertSet('customAllowed', true)->assertSet('remaining', 100)
        ->assertSee('Or write your own')->assertSee('100 interests left this month');
});

it('the dialog does not open for a profile the member may not see', function (string $case): void {
    $me = groom();
    $target = ivTarget();
    $code = match ($case) {
        'blocked pair' => tap($target->code, fn () => Block::factory()->create(['blocker_profile_id' => $target->id, 'blocked_profile_id' => $me->profile->id])),
        'not active' => tap($target->code, fn () => $target->forceFill(['status' => ProfileStatus::Suspended])->save()),
        'own profile' => $me->profile->code,
        'no such profile' => 'OPM99999999',
        'not a code' => '<script>alert(1)</script>',
    };
    $this->actingAs($me, 'web');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: $code)
        ->assertSet('code', '')->assertDispatched('toast')->assertNotDispatched('open-modal');
})->with(['blocked pair', 'not active', 'own profile', 'no such profile', 'not a code']);

it('F02: Send stores the interest with the chosen message, closes the dialog and switches the buttons for that profile', function (): void {
    $me = groom();
    $target = ivTarget();
    $this->actingAs($me, 'web');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: $target->code)
        ->set('template', 'T03')->call('send')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', name: 'send-interest')
        ->assertDispatched('interest-changed', code: $target->code, state: 'sent')
        ->assertDispatched('toast');

    $interest = Interest::query()->sole();
    expect($interest->from_profile_id)->toBe($me->profile->id)->and($interest->to_profile_id)->toBe($target->id)
        ->and($interest->message)->toBe('Your profile matches what I am looking for. Please have a look at mine.');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: ivTarget()->code)->set('template', '')->call('send');
    expect(Interest::query()->latest('id')->first()->message)->toBeNull();
});

it('M06 acceptance: when the month\'s interests are used up the upgrade dialog opens and the server refuses', function (): void {
    $me = groom();
    foreach (range(1, 3) as $i) {
        Livewire::actingAs($me, 'web')->test(InterestComposer::class)->dispatch('compose-interest', code: ivTarget()->code)->call('send');
    }
    $fourth = ivTarget();

    Livewire::actingAs($me, 'web')->test(InterestComposer::class)->dispatch('compose-interest', code: $fourth->code)
        ->assertSee('0 interests left this month')
        ->call('send')
        ->assertDispatched('quota-reached')->assertDispatched('close-modal', name: 'send-interest')
        ->assertNotDispatched('interest-changed');

    expect(Interest::query()->where('to_profile_id', $fourth->id)->exists())->toBeFalse()->and(Interest::query()->count())->toBe(3);
});

it('a note with contact details is refused under the field; a refusal with a reason is shown in the dialog', function (): void {
    $me = ivGold(groom());
    $target = ivTarget();
    $filtered = ivTarget();
    $preference = new PartnerPreference;
    $preference->forceFill(['profile_id' => $filtered->id, 'age_min' => 60, 'age_max' => 70])->save();
    $privacy = new PrivacySetting;
    $privacy->forceFill(['profile_id' => $filtered->id, 'contact_filter_enabled' => true])->save();
    $this->actingAs($me, 'web');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: $target->code)
        ->set('message', 'WhatsApp me on 9847012345')->call('send')
        ->assertHasErrors('message')->assertNotDispatched('close-modal')->assertNotDispatched('interest-changed')
        ->set('message', 'Our families are from the same town.')->call('send')
        ->assertHasNoErrors()->assertDispatched('interest-changed', code: $target->code, state: 'sent');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: $filtered->code)->call('send')
        ->assertSee('only accepts interests from profiles that match their partner preferences')
        ->assertNotDispatched('close-modal');

    expect(Interest::query()->count())->toBe(1)->and(Interest::query()->sole()->message)->toBe('Our families are from the same town.');
});

it('a Free member cannot slip a custom note past the dialog', function (): void {
    $me = groom();
    $target = ivTarget();
    $this->actingAs($me, 'web');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: $target->code)
        ->set('template', '')->set('message', 'my own words')->call('send')->assertDispatched('interest-changed');

    expect(Interest::query()->sole()->message)->toBeNull();
});

it('F02: sending to someone who already sent you one connects you at once', function (): void {
    $me = groom();
    $bride = bride();
    Interest::factory()->between($bride->profile, $me->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: $bride->profile->code)->call('send')
        ->assertDispatched('interest-changed', code: $bride->profile->code, state: 'connected');

    expect(Interest::query()->sole()->status)->toBe(InterestStatus::Accepted)->and(Conversation::query()->count())->toBe(1);
});

it('the dialog\'s profile, name, messages and allowance can\'t be tampered with; a support session is refused', function (string $property): void {
    $this->actingAs(groom(), 'web');

    expect(fn () => Livewire::test(InterestComposer::class)->set($property, match ($property) {
        'templates' => [], 'remaining' => 999, 'customAllowed' => true, default => 'OPM1',
    }))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['code', 'name', 'templates', 'remaining', 'customAllowed']);

it('P1.7b: an admin support session cannot send an interest from the dialog', function (): void {
    $me = groom();
    $target = ivTarget();
    $this->actingAs($me, 'web');
    session([Impersonation::SESSION_KEY => 'imp-1']);

    Livewire::test(InterestComposer::class)->dispatch('compose-interest', code: $target->code)->call('send')
        ->assertSee('Not available while our team is viewing this account')->assertNotDispatched('interest-changed');
    expect(Interest::query()->count())->toBe(0);
});

// ---- /interests -------------------------------------------------------------------------------------

it('/interests needs a signed-in, onboarded member', function (): void {
    $this->get(memberUrl('/interests'))->assertRedirect(route('login'));
    $this->actingAs(groom(), 'web')->get(memberUrl('/interests'))->assertOk()->assertSee('Interests Received');
});

it('received + pending: who sent it, their note, Accept / Decline — and the counts in the rail', function (): void {
    $me = bride();
    $sender = groom();
    $sender->profile->forceFill(['first_name' => 'Arun', 'last_name' => 'Menon'])->save();
    Interest::factory()->between($sender->profile, $me->profile)->create(['message' => 'Our families share <b>values</b>.']);
    Interest::factory()->between(groom('+919800000060')->profile, $me->profile)->declined()->create();
    $this->actingAs($me, 'web');

    Livewire::test(Interests::class)
        ->assertSet('box', 'received')->assertSet('status', 'pending')
        ->assertSee('Arun M.')->assertDontSee('Arun Menon')
        ->assertSee('Our families share <b>values</b>.')->assertDontSeeHtml('<b>values</b>')
        ->assertSeeHtml('class="btn-accept"')->assertSeeHtml('class="btn-decline"')
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="accept(')
        ->assertSee('reply before they expire')
        ->assertSeeInOrder(['All', '2', 'Pending', '1', 'Accepted', '0', 'Declined', '1']);
});

it('M06 acceptance: Accept connects the pair and tells the buttons; the row leaves "pending" and shows Chat under "accepted"', function (): void {
    $me = bride();
    $sender = groom();
    $sender->profile->forceFill(['first_name' => 'Arun', 'last_name' => 'Menon'])->save();
    Interest::factory()->between($sender->profile, $me->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::test(Interests::class)
        ->call('accept', $sender->profile->code)
        ->assertDispatched('interest-changed', code: $sender->profile->code, state: 'connected')->assertDispatched('toast')
        ->assertDontSee($sender->profile->code)
        ->call('show', 'received', 'accepted')
        ->assertSee('Arun Menon')                       // connected: the full name
        ->assertSeeHtml("interestButton('connected'")   // the Chat button
        ->assertSee('Accepted');

    expect(Interest::query()->sole()->status)->toBe(InterestStatus::Accepted)->and(Conversation::query()->count())->toBe(1);
});

it('Decline, Withdraw and "decline selected" go through their Actions; other codes change nothing', function (): void {
    $me = bride();
    [$a, $b, $c] = [groom('+919800000059'), groom('+919800000058'), groom('+919800000057')];
    foreach ([$a, $b, $c] as $sender) {
        Interest::factory()->between($sender->profile, $me->profile)->create();
    }
    $mine = Interest::factory()->between($me->profile, groom('+919800000056')->profile)->create();
    $foreign = Interest::factory()->between($a->profile, bride('+919800000055')->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::test(Interests::class)
        ->call('decline', $a->profile->code)->assertDispatched('interest-changed', code: $a->profile->code, state: 'none')
        ->set('selected', [$b->profile->code, $c->profile->code, 'junk', 'OPM99999999'])
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="declineSelected"')
        ->call('declineSelected')->assertSet('selected', [])
        ->assertSee('No interests here yet')
        ->call('decline', 'OPM99999999')->assertDispatched('toast')
        ->call('accept', $a->profile->code)                       // already declined: refused, nothing changes
        ->call('show', 'sent', 'pending')->assertSee('Awaiting reply')
        ->call('withdraw', Profile::query()->findOrFail($mine->to_profile_id)->code)
        ->assertSee('No interests here yet');

    expect(Interest::query()->where('to_profile_id', $me->profile->id)->pluck('status')->unique()->all())->toBe([InterestStatus::Declined])
        ->and($mine->refresh()->status)->toBe(InterestStatus::Withdrawn)
        ->and($foreign->refresh()->status)->toBe(InterestStatus::Pending)
        ->and(Conversation::query()->count())->toBe(0);
});

it('photo & horoscope requests: the owner approves or declines hers; an unknown type or someone else\'s request changes nothing', function (): void {
    $me = bride();
    $asker = groom();
    $other = groom('+919800000054');
    AccessRequest::factory()->between($asker->profile, $me->profile)->create();
    AccessRequest::factory()->between($other->profile, $me->profile, AccessRequestType::Horoscope)->create();
    $notMine = AccessRequest::factory()->between($asker->profile, bride('+919800000053')->profile)->create();
    $this->actingAs($me, 'web');

    Livewire::withQueryParams(['box' => 'requests'])->test(Interests::class)
        ->assertSee('Photo & Horoscope Requests')->assertSee('2 members are waiting for your answer')
        ->assertSee($asker->profile->code)->assertSee('Photos requested')->assertSee('Horoscope requested')
        ->call('answerRequest', $asker->profile->code, 'NONSENSE', true)
        ->call('answerRequest', $asker->profile->code, 'HOROSCOPE', true)   // he asked for photos, not this: nothing
        ->call('answerRequest', $asker->profile->code, 'PHOTO', true)->assertDispatched('toast')
        ->call('answerRequest', $other->profile->code, 'HOROSCOPE', false)
        ->assertSee('No requests waiting');

    expect(AccessRequest::query()->where('owner_profile_id', $me->profile->id)->pluck('status', 'type')->all())
        ->toBe(['PHOTO' => AccessRequestStatus::Approved, 'HOROSCOPE' => AccessRequestStatus::Declined])
        ->and($notMine->refresh()->status)->toBe(AccessRequestStatus::Pending);
});

it('/interests: box and status come from the URL and fall back when tampered with; it listens for live changes', function (): void {
    $me = groom();
    $this->actingAs($me, 'web');

    Livewire::withQueryParams(['box' => 'sent', 'status' => 'expired'])->test(Interests::class)
        ->assertSet('box', 'sent')->assertSet('status', 'expired')->assertSee('Interests Sent')
        ->call('show', "x'; drop table interests;--", '<script>')->assertSet('box', 'received')->assertSet('status', 'all');

    $page = Livewire::withQueryParams(['box' => 'nope', 'status' => 'nope'])->test(Interests::class)->assertSet('box', 'received')->assertSet('status', 'all');

    expect($page->instance()->getListeners())
        ->toHaveKey('echo-private:App.Models.User.'.$me->id.',.interest.changed')->toHaveKey('realtime-reconnected');
});

it('/interests paginates 20 a page, and a blocked sender is never listed', function (): void {
    $me = groom();
    foreach (range(1, 21) as $i) {
        Interest::factory()->between(ivTarget(), $me->profile)->create(['sent_at' => now()->subMinutes($i)]);
    }
    $oldest = Profile::query()->findOrFail(Interest::query()->orderBy('sent_at')->value('from_profile_id'));
    $blocked = ivTarget();
    Interest::factory()->between($blocked, $me->profile)->create();
    Block::factory()->create(['blocker_profile_id' => $me->profile->id, 'blocked_profile_id' => $blocked->id]);
    $this->actingAs($me, 'web');

    Livewire::test(Interests::class)->assertDontSee($oldest->code)->assertDontSee($blocked->code)
        ->call('gotoPage', 2)->assertSee($oldest->code);
});

it('P1.7b: a support session can answer nothing on /interests', function (): void {
    $me = bride();
    $sender = groom();
    Interest::factory()->between($sender->profile, $me->profile)->create();
    $this->actingAs($me, 'web');
    session([Impersonation::SESSION_KEY => 'imp-1']);

    Livewire::test(Interests::class)
        ->call('accept', $sender->profile->code)->assertDispatched('toast')->assertNotDispatched('interest-changed')
        ->call('decline', $sender->profile->code)
        ->set('selected', [$sender->profile->code])->call('declineSelected')
        ->assertSee($sender->profile->code);

    expect(Interest::query()->sole()->status)->toBe(InterestStatus::Pending);
});

// ---- Around the site --------------------------------------------------------------------------------

it('the profile page shows where the member stands: Send Interest → Interest sent → Respond → Chat', function (string $state): void {
    $me = groom();
    $bride = bride();
    match ($state) {
        'sent' => Interest::factory()->between($me->profile, $bride->profile)->create(),
        'received' => Interest::factory()->between($bride->profile, $me->profile)->create(),
        'connected' => Interest::factory()->between($bride->profile, $me->profile)->accepted()->create(),
        default => null,
    };
    $this->actingAs($me, 'web');

    Livewire::test(Show::class, ['profile' => $bride->profile->code])
        ->assertSeeHtml("interestButton('{$state}'")
        ->assertSee('Send Interest')->assertSee('Chat')
        ->assertDontSee('opening soon');
})->with(['none', 'sent', 'received', 'connected']);

it('M06: once connected the profile page shows the full name', function (): void {
    $me = groom();
    $bride = bride();
    $bride->profile->forceFill(['first_name' => 'Anjali', 'last_name' => 'Nair'])->save();
    $this->actingAs($me, 'web');

    Livewire::test(Show::class, ['profile' => $bride->profile->code])->assertSee('Anjali N.')->assertDontSee('Anjali Nair');

    Interest::factory()->between($me->profile, $bride->profile)->accepted()->create();
    app(App\Domain\Engagement\Connections::class)->forget();

    Livewire::test(Show::class, ['profile' => $bride->profile->code])->assertSee('Anjali Nair');
});

it('the profile page offers "Request photos" only where they are shared on request, and remembers the request', function (): void {
    $me = groom();
    $open = bride();
    $onRequest = ivOnRequest(bride('+919800000052')->profile);
    $this->actingAs($me, 'web');

    Livewire::test(Show::class, ['profile' => $open->profile->code])->assertDontSee('Request photos');

    Livewire::test(Show::class, ['profile' => $onRequest->code])
        ->assertSee('Request photos')->assertSeeHtml('wire:loading.attr="disabled" wire:target="requestAccess(')
        ->call('requestAccess', 'NONSENSE')
        ->call('requestAccess', 'PHOTO')
        ->assertSee('Request sent. You will be told when it is approved.')->assertSee('Photo request sent')->assertDontSee('Request photos');

    expect(AccessRequest::query()->sole()->status)->toBe(AccessRequestStatus::Pending);
});

it('M06 /favorites: "send interest to selected" sends what the month allows and says so', function (): void {
    $me = groom();
    $targets = collect(range(1, 4))->map(fn (): Profile => ivTarget());
    $targets->each(fn (Profile $t) => Favorite::factory()->between($me->profile, $t)->create());
    $this->actingAs($me, 'web');

    Livewire::test(Favorites::class)
        ->assertSee('Send interest to selected')->assertSeeHtml('wire:loading.attr="disabled" wire:target="interestSelected"')
        ->set('selected', $targets->take(2)->pluck('code')->all())->call('interestSelected')
        ->assertSet('selected', [])->assertDispatched('toast')->assertNotDispatched('quota-reached')
        ->set('selected', $targets->pluck('code')->all())->call('interestSelected')
        ->assertDispatched('quota-reached');

    expect(Interest::query()->where('from_profile_id', $me->profile->id)->count())->toBe(3);
});

it('R-M06-6: the search page offers "My favourites only" to the member, never in an admin support session', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(App\Livewire\Member\Search\Search::class)->assertSee('My favourites only')->assertSee('Hide profiles I\'ve sent an interest to');

    session([Impersonation::SESSION_KEY => 'imp-1']);
    Livewire::test(App\Livewire\Member\Search\Search::class)->assertDontSee('My favourites only');
});

it('list rows carry the interest state from one query for the page — no query per row', function (): void {
    $me = ivGold(groom());
    foreach (range(1, 5) as $i) {
        $liker = ivTarget();
        Like::factory()->between($liker, $me->profile)->create();
        if ($i === 1) {
            Interest::factory()->between($me->profile, $liker)->create();
        }
        if ($i === 2) {
            Interest::factory()->between($liker, $me->profile)->accepted()->create();
        }
    }
    $this->actingAs($me, 'web');

    DB::enableQueryLog();
    Livewire::test(Likes::class)
        ->assertSeeHtml("interestButton('sent'")->assertSeeHtml("interestButton('connected'")->assertSeeHtml("interestButton('none'");
    $interestQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'from `interests`'));

    // One for the page's button states, one for "who am I connected to" (names / photos) — however many rows.
    expect($interestQueries->count())->toBeLessThanOrEqual(2);
});
