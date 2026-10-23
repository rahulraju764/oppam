<?php

declare(strict_types=1);

use App\Actions\Admin\Members\GrantComplimentaryPlan;
use App\Actions\Billing\SyncMembershipFlags;
use App\Data\Search\SearchCriteria;
use App\Enums\Entitlement;
use App\Enums\MaritalStatus;
use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\EntitlementsChanged;
use App\Jobs\Billing\ExpireSubscriptions;
use App\Jobs\Billing\SendPlanExpiryReminders;
use App\Models\AdminUser;
use App\Models\Favorite;
use App\Models\Masters\Religion;
use App\Models\PartnerPreference;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Billing\PlanExpiredNotification;
use App\Notifications\Billing\PlanExpiringNotification;
use App\Services\Entitlements\EntitlementService;
use App\Services\Profile\ProfileSearch;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| P5.2 — M10 expiry and the §7.3 flags: SyncMembershipFlags keeps is_premium / highlighted_until /
| top_placement_until in step; ExpireSubscriptions reverts to Free limits (data kept), lets a queued
| plan take over and sends plan_expired once; SendPlanExpiryReminders (7 days / 1 day, IST) once
| each, never when a renewal is queued; Diamond's top placement in search.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

/** A subscription for the member on $plan from $starts for $days. */
function lifecycleSub(User $member, PlanCode $plan, string $starts, int $days): Subscription
{
    $start = CarbonImmutable::parse($starts, 'UTC');

    return Subscription::factory()->create([
        'profile_id' => $member->profile->id,
        'plan_id' => Plan::query()->where('code', $plan->value)->sole()->id,
        'starts_at' => $start,
        'ends_at' => $start->addDays($days),
    ]);
}

it('§7.3: flags follow the plan in force — premium for any plan, highlight for Gold / Diamond, top placement for Diamond only', function (): void {
    $member = groom();
    $sub = lifecycleSub($member, PlanCode::Diamond, now()->subDay()->toDateTimeString(), 30);

    app(SyncMembershipFlags::class)->handle($member->profile);
    $profile = $member->profile->refresh();

    expect($profile->is_premium)->toBeTrue()
        ->and($profile->highlighted_until?->equalTo($sub->ends_at))->toBeTrue()
        ->and($profile->top_placement_until?->equalTo($sub->ends_at))->toBeTrue();
});

it('M10: an ended plan expires — Free limits again, flags cleared, the open pages told, plan_expired once; data kept', function (): void {
    Event::fake([EntitlementsChanged::class]);
    $member = groom();
    lifecycleSub($member, PlanCode::Gold, now()->subDays(31)->toDateTimeString(), 30);
    app(SyncMembershipFlags::class)->handle($member->profile, CarbonImmutable::now()->subDays(2));
    Favorite::factory()->create(['profile_id' => $member->profile->id, 'favorite_profile_id' => bride()->profile->id]);
    expect($member->profile->refresh()->is_premium)->toBeTrue();

    app()->call([new ExpireSubscriptions, 'handle']);
    app()->call([new ExpireSubscriptions, 'handle']);   // re-run: nothing more happens

    $profile = $member->profile->refresh();
    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Expired)
        ->and($profile->is_premium)->toBeFalse()
        ->and($profile->highlighted_until)->toBeNull()
        ->and(app(EntitlementService::class)->plan($profile)->code)->toBe(PlanCode::Free)
        ->and(app(EntitlementService::class)->limit($profile, Entitlement::LikesPerDay))->toBe(10)
        ->and(Favorite::query()->count())->toBe(1);                // "data kept"

    Event::assertDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->userId === $member->id && $e->planCode === 'FREE');
    Event::assertDispatchedTimes(EntitlementsChanged::class, 1);
    Notification::assertSentToTimes($member, PlanExpiredNotification::class, 1);
});

it('M11 via is_premium: an expired member no longer counts as premium for "premium only" photos', function (): void {
    $member = groom();
    lifecycleSub($member, PlanCode::Silver, now()->subDays(31)->toDateTimeString(), 30);
    $member->profile->forceFill(['is_premium' => true])->save();   // as it was while the plan ran

    app()->call([new ExpireSubscriptions, 'handle']);

    expect($member->profile->refresh()->is_premium)->toBeFalse();
});

it('decisions 2026-10-21: a queued plan takes over at expiry — no plan_expired, flags for the new plan', function (): void {
    Event::fake([EntitlementsChanged::class]);
    $member = groom();
    $diamond = lifecycleSub($member, PlanCode::Diamond, now()->subDays(30)->toDateTimeString(), 29);   // ended yesterday
    lifecycleSub($member, PlanCode::Silver, $diamond->ends_at->toDateTimeString(), 30);               // queued after it

    app()->call([new ExpireSubscriptions, 'handle']);

    $profile = $member->profile->refresh();
    expect($profile->is_premium)->toBeTrue()
        ->and($profile->top_placement_until)->toBeNull()
        ->and($profile->highlighted_until)->toBeNull()
        ->and(app(EntitlementService::class)->plan($profile)->code)->toBe(PlanCode::Silver);

    Event::assertDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->planCode === 'SILVER');
    Notification::assertNotSentTo($member, PlanExpiredNotification::class);
});

it('M10 reminders: 7 days and 1 day before the end (IST calendar), each once', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 04:30:00', 'UTC'));   // 10:00 IST
    $week = groom('+919811100001');
    $tomorrow = groom('+919811100002');
    $later = groom('+919811100003');
    lifecycleSub($week, PlanCode::Gold, '2026-09-27 00:00:00', 30);           // ends 27 Oct
    lifecycleSub($tomorrow, PlanCode::Gold, '2026-09-23 17:00:00', 30);       // ends 23 Oct 17:00 UTC = 23 Oct 22:30 IST
    lifecycleSub($later, PlanCode::Gold, '2026-10-01 00:00:00', 30);          // ends 31 Oct — outside 7 days

    app()->call([new SendPlanExpiryReminders, 'handle']);
    app()->call([new SendPlanExpiryReminders, 'handle']);   // same day again: nothing new

    Notification::assertSentToTimes($week, PlanExpiringNotification::class, 1);
    Notification::assertSentToTimes($tomorrow, PlanExpiringNotification::class, 1);
    Notification::assertNotSentTo($later, PlanExpiringNotification::class);
    expect(Subscription::query()->where('profile_id', $tomorrow->profile->id)->sole()->reminded_1d_at)->not->toBeNull()
        ->and(Subscription::query()->where('profile_id', $tomorrow->profile->id)->sole()->reminded_7d_at)->toBeNull();

    // The 7-day member reaches the last day: the 1-day reminder follows.
    $this->travelTo(CarbonImmutable::parse('2026-10-26 04:30:00', 'UTC'));
    app()->call([new SendPlanExpiryReminders, 'handle']);
    Notification::assertSentToTimes($week, PlanExpiringNotification::class, 2);
});

it('M10 reminders: none when a renewal is already queued, none for a paused (suspended) plan', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 04:30:00', 'UTC'));
    $renewed = groom('+919811100004');
    $current = lifecycleSub($renewed, PlanCode::Gold, '2026-09-25 00:00:00', 30);
    lifecycleSub($renewed, PlanCode::Gold, $current->ends_at->toDateTimeString(), 30);
    $paused = groom('+919811100005');
    lifecycleSub($paused, PlanCode::Gold, '2026-09-25 00:00:00', 30)->forceFill(['paused_at' => now()])->save();

    app()->call([new SendPlanExpiryReminders, 'handle']);

    Notification::assertNotSentTo($renewed, PlanExpiringNotification::class);
    Notification::assertNotSentTo($paused, PlanExpiringNotification::class);
});

it('A03 complimentary grant: flags synced and the member\'s pages told live', function (): void {
    Event::fake([EntitlementsChanged::class]);
    $member = groom();
    $admin = AdminUser::factory()->create();
    $admin->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('billing.force_activate', 'admin'));

    app(GrantComplimentaryPlan::class)->handle($admin, $member, PlanCode::Diamond, 30, 'Goodwill after a support issue');

    expect($member->profile->refresh()->top_placement_until?->isFuture())->toBeTrue();
    Event::assertDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->planCode === 'DIAMOND');
});

it('§7.3 / decisions 2026-10-22: Diamond profiles come first in the relevance sort; the daily-matches pool ranks by fit alone', function (): void {
    $me = groom('+919877700020');
    $religion = Religion::query()->where('code', 'HINDU')->firstOrFail();
    PartnerPreference::factory()->create(['profile_id' => $me->profile->id, 'religion_ids' => [$religion->id], 'marital_statuses' => ['NEVER_MARRIED'],
        'age_min' => null, 'age_max' => null, 'height_min_cm' => null, 'height_max_cm' => null]);
    $bestFit = Profile::factory()->female()->active()->create(['religion_id' => $religion->id, 'caste_id' => null]);
    $diamond = Profile::factory()->female()->active()->create(['marital_status' => MaritalStatus::Divorced, 'caste_id' => null,
        'religion_id' => Religion::query()->where('code', 'CHRISTIAN')->value('id'), 'top_placement_until' => now()->addMonth()]);

    $search = app(ProfileSearch::class)->page($me->profile, SearchCriteria::fromInput([]))->profiles->pluck('code')->all();
    $pool = app(ProfileSearch::class)->topIds($me->profile, SearchCriteria::fromInput([]), 10);

    expect($search[0])->toBe($diamond->code)
        ->and($pool[0])->toBe($bestFit->id);
});

it('§7.3: a highlighted profile carries the Featured mark on result cards; an ended highlight does not', function (): void {
    $viewer = groom('+919877700021');
    $featured = Profile::factory()->female()->active()->create(['highlighted_until' => now()->addDays(5)]);
    $ended = Profile::factory()->female()->active()->create(['highlighted_until' => now()->subDay()]);

    $cards = collect(app(App\Domain\Profile\ProfileCards::class)->forViewers(collect([$featured, $ended]), $viewer))->keyBy('code');

    expect($cards[$featured->code]->isFeatured)->toBeTrue()
        ->and($cards[$ended->code]->isFeatured)->toBeFalse();
});

it('M09 / decision (l): result cards carry the Verified badge; a profile without is_verified loaded is not verified (P6.1 round 1)', function (): void {
    $viewer = groom('+919877700022');
    $verified = Profile::factory()->female()->active()->create(['is_verified' => true]);
    $plain = Profile::factory()->female()->active()->create();          // is_verified never set in memory

    $cards = collect(app(App\Domain\Profile\ProfileCards::class)->forViewers(collect([$verified, $plain]), $viewer))->keyBy('code');

    expect($cards[$verified->code]->isVerified)->toBeTrue()
        ->and($cards[$plain->code]->isVerified)->toBeFalse();
});

it('A03 + M10 review round 1: a suspension past the plan end does not expire it; reactivation extends it and its highlight', function (): void {
    seedAdminRoles();
    Event::fake([EntitlementsChanged::class]);
    $admin = adminWithRole('super_admin');
    $member = groom();
    $sub = lifecycleSub($member, PlanCode::Gold, now()->subDays(20)->toDateTimeString(), 30);   // ends in 10 days
    app(SyncMembershipFlags::class)->handle($member->profile);

    app(App\Actions\Admin\Members\SuspendMember::class)->handle($admin, $member->refresh(), 'Reported for abuse, reviewing');
    expect($member->profile->refresh()->is_premium)->toBeFalse();               // paused: not in force

    $this->travel(15)->days();                                                  // past the original end
    app()->call([new ExpireSubscriptions, 'handle']);
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);          // paused plans never expire

    app(App\Actions\Admin\Members\ReactivateMember::class)->handle($admin, $member->refresh(), 'Review complete, no issue');

    $sub->refresh();
    $profile = $member->profile->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->paused_at)->toBeNull()
        ->and($sub->ends_at->isFuture())->toBeTrue()                            // 10 days left again
        ->and($profile->is_premium)->toBeTrue()
        ->and($profile->highlighted_until?->equalTo($sub->ends_at))->toBeTrue();
    Notification::assertNotSentTo($member, PlanExpiredNotification::class);

    // The member's open pages are told both times: Free while suspended, Gold to the new end after.
    Event::assertDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->userId === $member->id && $e->planCode === 'FREE' && $e->until === '');
    $newEnd = $sub->ends_at->copy()->timezone('Asia/Kolkata')->format('j M Y');
    Event::assertDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->userId === $member->id && $e->planCode === 'GOLD' && $e->until === $newEnd);
});
