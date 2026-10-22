<?php

declare(strict_types=1);

use App\Actions\Billing\SyncMembershipFlags;
use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Actions\Engagement\ToggleFavorite;
use App\Actions\Engagement\ToggleLike;
use App\Actions\Profile\ViewContact;
use App\Enums\Entitlement;
use App\Enums\PhoneVisibility;
use App\Enums\PlanCode;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Profile\ContactNotAvailable;
use App\Livewire\Member\Activity\Visitors;
use App\Livewire\Member\Chat\Thread;
use App\Livewire\Member\Engagement\Likes;
use App\Models\ContactDetail;
use App\Models\Like;
use App\Models\PrivacySetting;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P5.2 "Done when" — PRD §7.3, every row × Free / Silver / Gold / Diamond, through the real Actions
| and screens (never only the UI). Quotas: the member's counter for the current period is set to
| the plan's limit (or far past it for an unlimited plan) and one more use must be refused — or
| must still work. Flags: the gated screen / action is checked as each plan sees it.
| "Relationship manager" is v2 (§7.3): nothing to enforce in v1.
*/

/** §7.3, as seeded (PlansSeeder): quota → limit (null = unlimited), flag → on / off. */
const PLAN_MATRIX_7_3 = [
    'FREE' => ['likes' => 10, 'favorites' => 25, 'interests' => 3, 'contacts' => 0, 'liked_me' => false, 'chat_send' => false, 'viewed_me' => false, 'highlight' => false, 'top' => false],
    'SILVER' => ['likes' => 50, 'favorites' => 200, 'interests' => 25, 'contacts' => 10, 'liked_me' => true, 'chat_send' => true, 'viewed_me' => false, 'highlight' => false, 'top' => false],
    'GOLD' => ['likes' => null, 'favorites' => null, 'interests' => 100, 'contacts' => 50, 'liked_me' => true, 'chat_send' => true, 'viewed_me' => true, 'highlight' => true, 'top' => false],
    'DIAMOND' => ['likes' => null, 'favorites' => null, 'interests' => null, 'contacts' => null, 'liked_me' => true, 'chat_send' => true, 'viewed_me' => true, 'highlight' => true, 'top' => true],
];

dataset('plans', [
    'Free' => [PlanCode::Free],
    'Silver' => [PlanCode::Silver],
    'Gold' => [PlanCode::Gold],
    'Diamond' => [PlanCode::Diamond],
]);

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

/** The member under test: a groom on $plan (Free = no subscription), flags in step. */
function matrixMember(PlanCode $plan): User
{
    $member = groom();
    if ($plan !== PlanCode::Free) {
        chatOnPlan($member, $plan);
    }
    app(SyncMembershipFlags::class)->handle($member->profile->refresh());

    return $member->refresh();
}

/** A live bride the groom can see, with a distinct phone. */
function matrixTarget(int $n = 1): User
{
    return bride('+9198000001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
}

/** Put the member's counter for the CURRENT period of $entitlement at $used. */
function matrixUsage(User $member, Entitlement $entitlement, int $used): void
{
    $period = app(EntitlementService::class)->period($member->profile, $entitlement);
    DB::table('entitlement_usages')->insert([
        'id' => (string) Str::ulid(),
        'profile_id' => $member->profile->id,
        'entitlement' => $entitlement->value,
        'period_start' => $period->start->toDateTimeString(),
        'period_end' => $period->end?->toDateTimeString(),
        'used' => $used,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(EntitlementService::class)->forget($member->profile);
}

/** At the limit for a limited plan; far past every limit for an unlimited one. */
function matrixFill(User $member, Entitlement $entitlement, ?int $limit): void
{
    matrixUsage($member, $entitlement, $limit ?? 100_000);
}

it('§7.3 search, view profiles and daily matches: every plan', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $target = matrixTarget();
    $this->actingAs($member, 'web');

    $this->get(memberUrl('/search'))->assertOk();
    $this->get(memberUrl('/profile/'.$target->profile->code))->assertOk()->assertSee($target->profile->code);
    $this->get(memberUrl('/matches/daily'))->assertOk();
})->with('plans');

it('§7.3 likes per day: Free 10 · Silver 50 · Gold / Diamond unlimited', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $limit = PLAN_MATRIX_7_3[$plan->value]['likes'];
    expect(app(EntitlementService::class)->limit($member->profile, Entitlement::LikesPerDay))->toBe($limit);
    matrixFill($member, Entitlement::LikesPerDay, $limit);

    $like = fn () => app(ToggleLike::class)->handle($member, matrixTarget()->profile, true);

    if ($limit === null) {
        expect($like()->liked)->toBeTrue();
    } else {
        expect($like)->toThrow(QuotaExceeded::class);
        expect(Like::query()->count())->toBe(0);
    }
})->with('plans');

it('§7.3 favourites (total): Free 25 · Silver 200 · Gold / Diamond unlimited', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $limit = PLAN_MATRIX_7_3[$plan->value]['favorites'];
    matrixFill($member, Entitlement::FavoritesTotal, $limit);

    $favorite = fn () => app(ToggleFavorite::class)->handle($member, matrixTarget()->profile, true);

    if ($limit === null) {
        expect($favorite())->toBeTrue();
    } else {
        expect($favorite)->toThrow(QuotaExceeded::class);
    }
})->with('plans');

it('§7.3 see who liked me: Free count + blurred list · Silver / Gold / Diamond the members', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $liker = matrixTarget()->profile;
    Like::factory()->between($liker, $member->profile)->create();
    $this->actingAs($member, 'web');

    $page = Livewire::test(Likes::class);

    PLAN_MATRIX_7_3[$plan->value]['liked_me']
        ? $page->assertSee($liker->code)->assertDontSee('See plans')
        : $page->assertSee('1 member liked your profile')->assertDontSee($liker->code)->assertSee('See plans');
})->with('plans');

it('§7.3 interests per month: Free 3 · Silver 25 · Gold 100 · Diamond unlimited', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $limit = PLAN_MATRIX_7_3[$plan->value]['interests'];
    expect(app(EntitlementService::class)->limit($member->profile, Entitlement::InterestsPerMonth))->toBe($limit);
    matrixFill($member, Entitlement::InterestsPerMonth, $limit);

    $send = fn () => app(SendInterest::class)->handle($member, matrixTarget()->profile, 'T01');

    $limit === null ? expect($send()->changed)->toBeTrue() : expect($send)->toThrow(QuotaExceeded::class);
})->with('plans');

it('§7.3 receive & accept interests: every plan (no quota is spent)', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $sender = chatOnPlan(matrixTarget(), PlanCode::Gold);
    app(SendInterest::class)->handle($sender, $member->profile, 'T01');
    matrixFill($member, Entitlement::InterestsPerMonth, PLAN_MATRIX_7_3[$plan->value]['interests']);   // even with no interests left

    expect(app(AcceptInterest::class)->handle($member, $sender->profile)->id)->not->toBeNull();
})->with('plans');

it('§7.3 chat: every plan reads; Silver / Gold / Diamond send; Free only its one reply (R-M07-3)', function (PlanCode $plan): void {
    [$groom, $bride] = chatConnected($plan === PlanCode::Free ? null : $plan, PlanCode::Gold);
    chatSend($bride, $groom, 'Namaskaram from Anna');
    $this->actingAs($groom, 'web');

    Livewire::test(Thread::class, ['withCode' => $bride->profile->code])->assertSee('Namaskaram from Anna');   // read: all

    chatSend($groom, $bride, 'Thank you');   // a paid send, or Free's one reply
    $second = fn () => chatSend($groom, $bride, 'And another');

    PLAN_MATRIX_7_3[$plan->value]['chat_send']
        ? expect($second()->body)->toBe('And another')
        : expect($second)->toThrow(HttpException::class);
})->with('plans');

it('§7.3 verified phone numbers per month: Free 0 · Silver 10 · Gold 50 · Diamond unlimited', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $limit = PLAN_MATRIX_7_3[$plan->value]['contacts'];
    $target = matrixTarget();
    (new ContactDetail)->forceFill(['profile_id' => $target->profile->id, 'contact_person' => 'Gopalan', 'contact_relation' => 'Father'])->save();
    $privacy = PrivacySetting::query()->whereKey($target->profile->id)->first() ?? new PrivacySetting;
    $privacy->forceFill(['profile_id' => $target->profile->id, 'phone_visibility' => PhoneVisibility::PremiumOnly, 'contact_filter_enabled' => false])->save();
    if ($limit !== 0) {
        matrixFill($member, Entitlement::ContactViewsPerMonth, $limit);
    }

    $reveal = fn () => app(ViewContact::class)->handle($member, $target->profile->refresh());

    $limit === null ? expect($reveal()->phone)->not->toBeNull() : expect($reveal)->toThrow(ContactNotAvailable::class);
})->with('plans');

it('§7.3 see who viewed me: Free / Silver count only · Gold / Diamond the members', function (PlanCode $plan): void {
    $member = matrixMember($plan);
    $visitor = matrixTarget()->profile;
    ProfileView::factory()->create(['viewer_profile_id' => $visitor->id, 'viewed_profile_id' => $member->profile->id]);
    $this->actingAs($member, 'web');

    $page = Livewire::test(Visitors::class);

    PLAN_MATRIX_7_3[$plan->value]['viewed_me']
        ? $page->assertSee($visitor->code)
        : $page->assertSee('1 member viewed your profile')->assertDontSee($visitor->code)->assertSee('See plans');
})->with('plans');

it('§7.3 highlighted in search (Gold / Diamond) and top placement (Diamond): the profile flags search reads', function (PlanCode $plan): void {
    $profile = matrixMember($plan)->profile;

    expect($profile->is_premium)->toBe($plan !== PlanCode::Free)
        ->and($profile->highlighted_until?->isFuture() === true)->toBe(PLAN_MATRIX_7_3[$plan->value]['highlight'])
        ->and($profile->top_placement_until?->isFuture() === true)->toBe(PLAN_MATRIX_7_3[$plan->value]['top']);
})->with('plans');
