<?php

declare(strict_types=1);

use App\Actions\Engagement\MarkLikesSeen;
use App\Actions\Engagement\ToggleLike;
use App\Domain\Matching\DailyBatch;
use App\Enums\EngagementSignalType;
use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Events\Engagement\LikeChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Block;
use App\Models\DailyMatch;
use App\Models\EngagementSignal;
use App\Models\Ignore;
use App\Models\Like;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Engagement\MutualLikeNotification;
use App\Notifications\Engagement\ProfileLikedNotification;
use App\Queries\Engagement\EngagementLists;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P3.3 — likes (PRD §10 M06, §7.3, §12 F02): ToggleLike's rules (idempotent, daily limit, spam
| guard, mutual detection, never an "unliked" notification), who may like whom (the security
| matrix), the live event's payload, and the like lists.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Event::fake([LikeChanged::class]);
});

function enPlan(User $member, PlanCode $plan): User
{
    Subscription::factory()->create([
        'profile_id' => $member->profile->id,
        'plan_id' => Plan::query()->where('code', $plan->value)->sole()->id,
    ]);
    app(EntitlementService::class)->forget($member->profile);

    return $member;
}

function enLike(User $liker, Profile $target, bool $like = true): App\Data\Engagement\LikeResult
{
    return app(ToggleLike::class)->handle($liker, $target, $like);
}

/** A visible female profile without a login (cheap targets for limit tests). */
function enTarget(): Profile
{
    return Profile::factory()->female()->active()->create();
}

// ---- The like itself ------------------------------------------------------------------------

it('F02: a like stores one row, uses one of today\'s likes, and tells the liked member live and in-app', function (): void {
    $groom = groom();
    $bride = bride();

    $result = enLike($groom, $bride->profile);

    expect($result->liked)->toBeTrue()->and($result->mutual)->toBeFalse()->and($result->changed)->toBeTrue()
        ->and(Like::query()->where('from_profile_id', $groom->profile->id)->where('to_profile_id', $bride->profile->id)->count())->toBe(1)
        ->and(app(EntitlementService::class)->used($groom->profile, Entitlement::LikesPerDay))->toBe(1);

    Event::assertDispatched(LikeChanged::class, fn (LikeChanged $e): bool => $e->toUserId === $bride->id && $e->liked && ! $e->isMutual);
    Notification::assertSentTo($bride, ProfileLikedNotification::class);
    Notification::assertNotSentTo($groom, ProfileLikedNotification::class);
});

it('R-M06-1: liking twice and un-liking twice change nothing the second time', function (): void {
    $groom = groom();
    $bride = bride();

    enLike($groom, $bride->profile);
    $again = enLike($groom, $bride->profile);

    expect($again->liked)->toBeTrue()->and($again->changed)->toBeFalse()
        ->and(Like::query()->count())->toBe(1)
        ->and(app(EntitlementService::class)->used($groom->profile, Entitlement::LikesPerDay))->toBe(1);
    Notification::assertSentToTimes($bride, ProfileLikedNotification::class, 1);

    expect(enLike($groom, $bride->profile, false)->changed)->toBeTrue()
        ->and(enLike($groom, $bride->profile, false)->changed)->toBeFalse()
        ->and(Like::query()->count())->toBe(0);
});

it('R-M06-5: un-liking silently removes the like — no notification, no name, and today\'s like is not given back', function (): void {
    $groom = groom();
    $bride = enPlan(bride(), PlanCode::Gold);   // a plan that would be told who liked
    enLike($groom, $bride->profile);
    Notification::fake();
    Event::fake([LikeChanged::class]);

    $result = enLike($groom, $bride->profile, false);

    expect($result->liked)->toBeFalse()->and(Like::query()->count())->toBe(0)
        ->and(app(EntitlementService::class)->used($groom->profile, Entitlement::LikesPerDay))->toBe(1);
    Notification::assertNothingSent();
    Event::assertDispatched(LikeChanged::class, fn (LikeChanged $e): bool => $e->toUserId === $bride->id
        && $e->broadcastWith() === ['from_code' => null, 'liked' => false, 'is_mutual' => false]);
});

it('F02: liking back makes it mutual — "It\'s a match!" goes to both, by name on every plan', function (): void {
    $groom = groom();
    $bride = bride();
    enLike($groom, $bride->profile);
    Event::fake([LikeChanged::class]);

    $result = enLike($bride, $groom->profile);

    expect($result->mutual)->toBeTrue()->and($result->changed)->toBeTrue();
    Notification::assertSentTo($groom, MutualLikeNotification::class, fn (MutualLikeNotification $n): bool => $n->otherProfileId === $bride->profile->id);
    Notification::assertSentTo($bride, MutualLikeNotification::class, fn (MutualLikeNotification $n): bool => $n->otherProfileId === $groom->profile->id);
    Notification::assertNotSentTo($groom, ProfileLikedNotification::class);
    // Free plans too: each of them chose the other.
    Event::assertDispatched(LikeChanged::class, fn (LikeChanged $e): bool => $e->toUserId === $groom->id
        && $e->broadcastWith() === ['from_code' => $bride->profile->code, 'liked' => true, 'is_mutual' => true]);

    $content = (new MutualLikeNotification($bride->profile->id))->content($groom);
    expect($content->title)->toContain('It’s a match!')->and($content->actorCode)->toBe($bride->profile->code);
});

it('R-M08-2: a pair hears "It\'s a match!" once a day, whichever side un-likes and likes again', function (): void {
    $groom = groom();
    $bride = bride();
    enLike($groom, $bride->profile);
    enLike($bride, $groom->profile);        // mutual: both notified

    enLike($groom, $bride->profile, false);
    enLike($groom, $bride->profile);        // he completes the pair again
    enLike($bride, $groom->profile, false);
    enLike($bride, $groom->profile);        // she completes it again

    Notification::assertSentToTimes($groom, MutualLikeNotification::class, 1);
    Notification::assertSentToTimes($bride, MutualLikeNotification::class, 1);
});

it('mass assignment: likes, favourites and signals accept no attributes from input', function (): void {
    expect((new Like)->getFillable())->toBe([])
        ->and((new App\Models\Favorite)->getFillable())->toBe([])
        ->and((new EngagementSignal)->getFillable())->toBe([])
        ->and(fn () => Like::query()->create(['from_profile_id' => 'x', 'to_profile_id' => 'y', 'seen_at' => now()]))
        ->toThrow(Illuminate\Database\Eloquent\MassAssignmentException::class);
});

it('R-M08-2: like, un-like, like again on the same day notifies once', function (): void {
    $groom = groom();
    $bride = bride();

    enLike($groom, $bride->profile);
    enLike($groom, $bride->profile, false);
    enLike($groom, $bride->profile);
    Notification::assertSentToTimes($bride, ProfileLikedNotification::class, 1);

    $this->travel(1)->days();
    enLike($groom, $bride->profile, false);
    enLike($groom, $bride->profile);
    Notification::assertSentToTimes($bride, ProfileLikedNotification::class, 2);
});

it('M06: who liked is named only for plans with "see who liked me" — live and in the notification', function (): void {
    $groom = groom();
    $free = bride();
    $gold = enPlan(bride('+919800000097'), PlanCode::Gold);

    enLike($groom, $free->profile);
    enLike($groom, $gold->profile);

    Event::assertDispatched(LikeChanged::class, fn (LikeChanged $e): bool => $e->toUserId === $free->id && $e->fromCode === null);
    Event::assertDispatched(LikeChanged::class, fn (LikeChanged $e): bool => $e->toUserId === $gold->id && $e->fromCode === $groom->profile->code);

    $anonymous = (new ProfileLikedNotification($groom->profile->id))->content($free);
    $named = (new ProfileLikedNotification($groom->profile->id))->content($gold);
    expect($anonymous->title)->toBe('Someone liked your profile')->and($anonymous->actorCode)->toBeNull()->and($anonymous->url)->toBe(route('member.likes'))
        ->and($named->actorCode)->toBe($groom->profile->code)->and($named->title)->toContain('liked your profile');
});

it('M05: liking today\'s daily match marks it as acted on', function (): void {
    $groom = groom();
    $bride = bride();
    DailyMatch::factory()->create(['profile_id' => $groom->profile->id, 'matched_profile_id' => $bride->profile->id, 'match_date' => DailyBatch::today()]);

    enLike($groom, $bride->profile);

    expect(DailyMatch::query()->where('profile_id', $groom->profile->id)->value('is_interacted'))->toBeTruthy();
});

it('a like on a profile without a login (bureau-managed) is stored without notifying anyone', function (): void {
    $groom = groom();
    $managed = enTarget();
    $managed->forceFill(['user_id' => null])->save();

    expect(enLike($groom, $managed->refresh())->changed)->toBeTrue();
    Notification::assertNothingSent();
    Event::assertNotDispatched(LikeChanged::class);
});

// ---- Limits ---------------------------------------------------------------------------------

it('§7.3: a Free member has 10 likes a day — the 11th is refused and nothing is written; Gold is unlimited', function (): void {
    $free = groom();
    foreach (range(1, 10) as $i) {
        enLike($free, enTarget());
    }

    expect(fn () => enLike($free, enTarget()))->toThrow(QuotaExceeded::class)
        ->and(Like::query()->where('from_profile_id', $free->profile->id)->count())->toBe(10)
        ->and(app(EntitlementService::class)->used($free->profile, Entitlement::LikesPerDay))->toBe(10);

    $this->travelTo(now('Asia/Kolkata')->addDay()->startOfDay()->addMinute());   // counters reset at 00:00 IST
    expect(enLike($free, enTarget())->changed)->toBeTrue();

    $gold = enPlan(groom('+919800000096'), PlanCode::Gold);
    foreach (range(1, 12) as $i) {
        enLike($gold, enTarget());
    }
    expect(Like::query()->where('from_profile_id', $gold->profile->id)->count())->toBe(12);
});

it('R-M06-2: too many likes in 10 minutes soft-blocks liking and leaves one signal for the A13 monitors', function (): void {
    Setting::factory()->keyed(SettingKey::LikesSpamMaxPer10Minutes, 5)->create();
    Setting::factory()->keyed(SettingKey::LikesSpamBlockMinutes, 60)->create();
    Settings::flush();
    $gold = enPlan(groom(), PlanCode::Gold);
    foreach (range(1, 5) as $i) {
        enLike($gold, enTarget());
    }

    $refusal = null;
    try {
        enLike($gold, enTarget());
    } catch (EngagementRefused $e) {
        $refusal = $e;
    }

    expect($refusal?->reason)->toBe(EngagementRefused::LIKE_SPAM)
        ->and($refusal?->getMessage())->toContain('60 minutes')
        ->and(fn () => enLike($gold, enTarget()))->toThrow(EngagementRefused::class)   // still blocked
        ->and(Like::query()->where('from_profile_id', $gold->profile->id)->count())->toBe(5)
        ->and(EngagementSignal::query()->where('profile_id', $gold->profile->id)->where('type', EngagementSignalType::LikeSpam->value)->count())->toBe(1);

    $signal = EngagementSignal::query()->sole();
    expect($signal->details)->toBe(['likes' => 5, 'window_minutes' => 10, 'blocked_minutes' => 60]);

    // Un-liking is never blocked; after the hour liking works again.
    $liked = Profile::query()->findOrFail(Like::query()->value('to_profile_id'));
    expect(enLike($gold, $liked, false)->changed)->toBeTrue();
    $this->travel(61)->minutes();
    expect(enLike($gold, enTarget())->changed)->toBeTrue();
});

it('R-M06-2: likes spread over more than 10 minutes are not spam', function (): void {
    Setting::factory()->keyed(SettingKey::LikesSpamMaxPer10Minutes, 5)->create();
    Settings::flush();
    $gold = enPlan(groom(), PlanCode::Gold);

    foreach (range(1, 5) as $i) {
        enLike($gold, enTarget());
    }
    $this->travel(11)->minutes();
    foreach (range(1, 5) as $i) {
        enLike($gold, enTarget());
    }

    expect(Like::query()->count())->toBe(10)->and(EngagementSignal::query()->count())->toBe(0);
});

// ---- Who may like whom (security matrix) ----------------------------------------------------

it('refuses with a 404 and writes nothing', function (string $case): void {
    $groom = groom();
    $bride = bride();

    $target = match ($case) {
        'own profile' => $groom->profile,
        'same gender' => groom('+919800000095')->profile,
        'target not ACTIVE' => tap($bride->profile, fn (Profile $p) => $p->forceFill(['status' => ProfileStatus::Suspended])->save()),
        'liker blocked the target' => tap($bride->profile, fn (Profile $p) => Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $p->id])),
        'target blocked the liker' => tap($bride->profile, fn (Profile $p) => Block::factory()->create(['blocker_profile_id' => $p->id, 'blocked_profile_id' => $groom->profile->id])),
        'suspended liker' => tap($bride->profile, fn () => $groom->forceFill(['status' => UserStatus::Suspended])->save()),
    };

    expect(fn () => enLike($groom->refresh(), $target))->toThrow(NotFoundHttpException::class)
        ->and(Like::query()->count())->toBe(0)
        ->and(app(EntitlementService::class)->used($groom->profile, Entitlement::LikesPerDay))->toBe(0);
    Notification::assertNothingSent();
    Event::assertNotDispatched(LikeChanged::class);
})->with(['own profile', 'same gender', 'target not ACTIVE', 'liker blocked the target', 'target blocked the liker', 'suspended liker']);

it('a member whose own profile is not approved yet cannot like', function (): void {
    $pending = memberWithPhone('+919800000094', ProfileStatus::PendingReview);
    $pending->profile->forceFill(['gender' => App\Enums\Gender::Male])->save();

    $refusal = null;
    try {
        enLike($pending->refresh(), bride()->profile);
    } catch (EngagementRefused $e) {
        $refusal = $e;
    }

    expect($refusal?->reason)->toBe(EngagementRefused::PROFILE_NOT_ACTIVE)->and(Like::query()->count())->toBe(0);
});

it('P1.7b: an admin support session can neither like nor un-like for the member', function (): void {
    $groom = groom();
    $bride = bride();
    Like::factory()->between($groom->profile, $bride->profile)->create();
    session([Impersonation::SESSION_KEY => 'imp-1']);

    expect(fn () => enLike($groom, enTarget()))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => enLike($groom, $bride->profile, false))->toThrow(ImpersonationRestricted::class)
        ->and(Like::query()->count())->toBe(1);
});

it('a like can still be taken back after the other profile was hidden or blocked', function (): void {
    $groom = groom();
    $bride = bride();
    Like::factory()->between($groom->profile, $bride->profile)->create();
    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);

    expect(enLike($groom, $bride->profile, false)->changed)->toBeTrue()->and(Like::query()->count())->toBe(0);
});

// ---- The live event ---------------------------------------------------------------------------

it('§9.4: LikeChanged goes to the liked member\'s private channel on the broadcasts queue with codes only', function (): void {
    $groom = groom();
    $bride = bride();
    $event = new LikeChanged($bride->id, $groom->profile->id, $bride->profile->id, $groom->profile->code, true, false);

    expect($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$bride->id)
        ->and($event->broadcastAs())->toBe('like.changed')
        ->and($event->queue)->toBe('broadcasts')
        ->and($event->broadcastWith())->toBe(['from_code' => $groom->profile->code, 'liked' => true, 'is_mutual' => false])
        ->and(json_encode($event->broadcastWith()))->not->toContain($groom->profile->id)->not->toContain($groom->id)
        ->and($event->broadcastWhen())->toBeTrue()
        ->and($event)->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});

it('§9.4 / R-M06-4: LikeChanged is never delivered inside a blocked pair', function (string $direction): void {
    $groom = groom();
    $bride = bride();
    $direction === 'liker blocked'
        ? Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id])
        : Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);

    expect((new LikeChanged($bride->id, $groom->profile->id, $bride->profile->id, null, false, false))->broadcastWhen())->toBeFalse();
})->with(['liker blocked', 'liked blocked']);

// ---- Lists ------------------------------------------------------------------------------------

it('M06 lists: liked me / I liked / mutual, newest first — blocked, ignored and suspended members never listed nor counted', function (): void {
    $me = groom();
    $lists = app(EngagementLists::class);
    $first = enTarget();
    $second = enTarget();
    $mutual = enTarget();
    $blocked = enTarget();
    $ignored = enTarget();
    $suspended = enTarget();

    foreach ([$first, $second, $mutual, $blocked, $ignored, $suspended] as $i => $liker) {
        Like::factory()->between($liker, $me->profile)->create(['created_at' => now()->subMinutes(60 - $i)]);
    }
    Like::factory()->between($me->profile, $mutual)->create();
    Like::factory()->between($me->profile, $suspended)->create();
    Block::factory()->create(['blocker_profile_id' => $blocked->id, 'blocked_profile_id' => $me->profile->id]);
    Ignore::factory()->create(['ignorer_profile_id' => $me->profile->id, 'ignored_profile_id' => $ignored->id]);
    $suspended->forceFill(['status' => ProfileStatus::Suspended])->save();

    expect($lists->likedMe($me->profile)->pluck('profiles.code')->all())->toBe([$mutual->code, $second->code, $first->code])
        ->and($lists->likedMe($me->profile)->count())->toBe(3)
        ->and($lists->likedByMe($me->profile)->pluck('profiles.code')->all())->toBe([$mutual->code])
        ->and($lists->mutual($me->profile)->pluck('profiles.code')->all())->toBe([$mutual->code])
        ->and($lists->newLikesCount($me->profile))->toBe(3);
});

it('M06 `likes.seen_at`: opening "Liked me" stamps only the member\'s own unseen likes; never during a support session', function (): void {
    $me = groom();
    $other = groom('+919800000093');
    Like::factory()->between(enTarget(), $me->profile)->create();
    Like::factory()->between(enTarget(), $me->profile)->seen()->create();
    Like::factory()->between(enTarget(), $other->profile)->create();

    session([Impersonation::SESSION_KEY => 'imp-1']);
    expect(app(MarkLikesSeen::class)->handle($me))->toBe(0);
    session()->forget(Impersonation::SESSION_KEY);

    expect(app(MarkLikesSeen::class)->handle($me))->toBe(1)
        ->and(Like::query()->where('to_profile_id', $me->profile->id)->whereNull('seen_at')->count())->toBe(0)
        ->and(Like::query()->where('to_profile_id', $other->profile->id)->whereNull('seen_at')->count())->toBe(1);
});

it('batch state for a page of cards: which of them I already like or bookmarked, in two queries', function (): void {
    $me = groom();
    $liked = enTarget();
    $saved = enTarget();
    $neither = enTarget();
    Like::factory()->between($me->profile, $liked)->create();
    App\Models\Favorite::factory()->between($me->profile, $saved)->create();
    Like::factory()->between(groom('+919800000092')->profile, $neither)->create();   // someone else's like

    Illuminate\Support\Facades\DB::enableQueryLog();
    $state = app(EngagementLists::class)->stateFor($me->profile, [$liked->id, $saved->id, $neither->id]);

    expect(Illuminate\Support\Facades\DB::getQueryLog())->toHaveCount(2)
        ->and($state)->toBe(['liked' => [$liked->id => true], 'favorited' => [$saved->id => true]]);
});
