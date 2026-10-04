<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Livewire\Member\Activity\Visitors;
use App\Models\Block;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Livewire\Livewire;

/*
| P2.4 — /visitors (M15): "who viewed me" in full for Gold / Diamond, a count + data-free teaser for
| others; "profiles I viewed" for everyone; 90 days, distinct members; blocked / suspended never.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function visitorOf(User $me, array $state = [], int $daysAgo = 1): Profile
{
    $visitor = Profile::factory()->female()->active()->create($state);
    ProfileView::factory()->create(['viewer_profile_id' => $visitor->id, 'viewed_profile_id' => $me->profile->id,
        'view_date' => now()->subDays($daysAgo)->toDateString(), 'updated_at' => now()->subDays($daysAgo)]);

    return $visitor;
}

function makeGold(User $me): void
{
    App\Models\Subscription::factory()->create([
        'profile_id' => $me->profile->id,
        'plan_id' => App\Models\Plan::query()->where('code', PlanCode::Gold->value)->sole()->id,
    ]);
    app(App\Services\Entitlements\EntitlementService::class)->forget($me->profile);
}

it('needs a signed-in, onboarded member', function (): void {
    $this->get(memberUrl('/visitors'))->assertRedirect(route('login'));
    $this->actingAs(groom(), 'web')->get(memberUrl('/visitors'))->assertOk()->assertSee('Who viewed me');
});

it('M15: a Free member sees only the number and an upgrade link — no visitor data', function (): void {
    $me = groom();
    $visitor = visitorOf($me);
    $this->actingAs($me, 'web');

    Livewire::test(Visitors::class)
        ->assertSee('1 member viewed your profile')->assertSee('See plans')
        ->assertDontSee($visitor->code)->assertDontSee($visitor->first_name);
});

it('M15: Gold sees each visitor once, newest visit first, with when', function (): void {
    $me = groom();
    $older = visitorOf($me, daysAgo: 5);
    $newer = visitorOf($me, daysAgo: 1);
    ProfileView::factory()->create(['viewer_profile_id' => $older->id, 'viewed_profile_id' => $me->profile->id,
        'view_date' => now()->subDays(3)->toDateString(), 'updated_at' => now()->subDays(3)]);   // second day, same visitor
    makeGold($me);
    $this->actingAs($me->refresh(), 'web');

    $html = Livewire::test(Visitors::class)->assertSee('2 ')->assertSee('Viewed you')->html();

    expect(substr_count($html, 'profile/'.$older->code))->toBe(substr_count($html, 'profile/'.$newer->code))
        ->and(strpos($html, $newer->code))->toBeLessThan(strpos($html, $older->code));
});

it('blocked (either way), suspended and incognito visitors and visits older than 90 days are never listed nor counted', function (): void {
    $me = groom();
    $blocked = visitorOf($me);
    $blocker = visitorOf($me);
    $suspended = visitorOf($me, ['status' => ProfileStatus::Suspended]);
    $old = visitorOf($me, daysAgo: 100);
    $incognito = visitorOf($me);
    (App\Models\PrivacySetting::query()->whereKey($incognito->id)->first() ?? new App\Models\PrivacySetting)
        ->forceFill(['profile_id' => $incognito->id, 'incognito' => true])->save();
    $ok = visitorOf($me);
    Block::factory()->create(['blocker_profile_id' => $me->profile->id, 'blocked_profile_id' => $blocked->id]);
    Block::factory()->create(['blocker_profile_id' => $blocker->id, 'blocked_profile_id' => $me->profile->id]);
    makeGold($me);
    $this->actingAs($me->refresh(), 'web');

    Livewire::test(Visitors::class)
        ->assertSee($ok->code)
        ->assertDontSee($blocked->code)->assertDontSee($blocker->code)->assertDontSee($suspended->code)->assertDontSee($old->code)->assertDontSee($incognito->code)
        ->assertViewHas('visitorCount', 1);
});

it('M15: "Profiles I viewed" is open to every plan', function (): void {
    $me = groom();
    $seen = Profile::factory()->female()->active()->create();
    ProfileView::factory()->create(['viewer_profile_id' => $me->profile->id, 'viewed_profile_id' => $seen->id]);
    $this->actingAs($me, 'web');

    Livewire::test(Visitors::class)->call('show', 'viewed')->assertSet('tab', 'viewed')
        ->assertSee($seen->code)->assertSee('You viewed');
});

it('an unknown tab falls back to visitors; empty states', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(Visitors::class)->call('show', 'everything')->assertSet('tab', 'visitors');
    Livewire::test(Visitors::class)->call('show', 'viewed')->assertSee('You haven’t viewed any profiles yet');
});

it('a Free member with no visitors gets the empty state, not "0 members"', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(Visitors::class)->assertSee('No visitors yet')->assertDontSee('0 members viewed');
});
