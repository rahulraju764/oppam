<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use App\Livewire\Member\Dashboard\Dashboard;
use App\Livewire\Member\Dashboard\Strip;
use App\Models\Block;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Models\ProfileView;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P2.3 — /dashboard (M05): own profile card + menu, and the lazy sliders (daily batch, new,
| mutual, premium, recently viewed you — Gold+ only, others a data-free teaser).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function dashBride(array $state = []): Profile
{
    return Profile::factory()->female()->active()->create($state);
}

it('needs a signed-in, onboarded member; renders the profile card and only existing menu pages', function (): void {
    $this->get(memberUrl('/dashboard'))->assertRedirect(route('login'));

    $me = groom();
    $this->actingAs($me, 'web')->get(memberUrl('/dashboard'))->assertOk()
        ->assertSee($me->profile->code)->assertSee('Profile completeness')
        ->assertSee('My Matches')->assertSee('Visitors')
        ->assertDontSee('VishwakarmaMatrimony')
        ->assertSeeHtml('wire:name="member.dashboard.strip"');   // the sliders load lazily
});

it('runs no match query itself and renders without lazy loading', function (): void {
    Model::preventLazyLoading();
    $this->actingAs(groom(), 'web');

    DB::enableQueryLog();
    Livewire::test(Dashboard::class)->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'partner_preferences') && str_contains($q, 'not exists')))->toBeEmpty();
});

it('the daily strip shows today\'s batch, best first, without dismissed or now-blocked profiles', function (): void {
    $me = groom();
    $top = dashBride();
    $second = dashBride();
    $dismissed = dashBride();
    $blocked = dashBride();
    $yesterday = dashBride();
    $today = now('Asia/Kolkata')->toDateString();
    foreach ([[$top, 90, false], [$second, 70, false], [$dismissed, 95, true], [$blocked, 80, false]] as [$p, $score, $done]) {
        DailyMatch::factory()->create(['profile_id' => $me->profile->id, 'matched_profile_id' => $p->id, 'match_date' => $today, 'score' => $score, 'is_interacted' => $done]);
    }
    DailyMatch::factory()->create(['profile_id' => $me->profile->id, 'matched_profile_id' => $yesterday->id, 'match_date' => now('Asia/Kolkata')->subDay()->toDateString()]);
    Block::factory()->create(['blocker_profile_id' => $blocked->id, 'blocked_profile_id' => $me->profile->id]);
    $this->actingAs($me, 'web');

    $html = Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'daily'])->assertSee('Time left to view')->html();

    expect($html)->toContain($top->first_name)
        ->and(strpos($html, (string) $top->first_name))->toBeLessThan(strpos($html, (string) $second->first_name))
        ->and($html)->not->toContain($dismissed->code)->not->toContain($blocked->code)->not->toContain($yesterday->code);
});

it('the daily strip says when the first batch arrives', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'daily'])->assertSee('arrive every morning at 5 AM');
});

it('M15: "recently viewed you" lists visitors for Gold, and only a count + upgrade for Free', function (): void {
    $me = groom();
    $visitor = dashBride();
    ProfileView::factory()->create(['viewer_profile_id' => $visitor->id, 'viewed_profile_id' => $me->profile->id]);
    $this->actingAs($me, 'web');

    Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'visitors'])
        ->assertSee('1 member viewed your profile')->assertSee('Upgrade to see who')
        ->assertDontSee($visitor->first_name);

    App\Models\Subscription::factory()->create([
        'profile_id' => $me->profile->id,
        'plan_id' => App\Models\Plan::query()->where('code', PlanCode::Gold->value)->sole()->id,
    ]);
    app(App\Services\Entitlements\EntitlementService::class)->forget($me->profile);
    Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'visitors'])
        ->assertSee($visitor->first_name)->assertDontSee('Upgrade to see who');
});

it('the new / mutual / premium strips come from the match funnel', function (): void {
    $me = groom();
    $premium = dashBride(['is_premium' => true]);
    $this->actingAs($me, 'web');

    Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'premium'])->assertSee($premium->first_name)->assertSee('View All');
    Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'new'])->assertSee($premium->first_name);
});

it('an unknown strip is a 404 and the kind can\'t be tampered with', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'everything'])->assertStatus(404);

    expect(fn () => Livewire::withoutLazyLoading()->test(Strip::class, ['kind' => 'new'])->set('kind', 'visitors'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
