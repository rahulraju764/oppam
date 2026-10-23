<?php

declare(strict_types=1);

use App\Actions\Engagement\SendInterest;
use App\Actions\Engagement\ToggleFavorite;
use App\Actions\Engagement\ToggleLike;
use App\Actions\Safety\BlockProfile;
use App\Actions\Safety\IgnoreProfile;
use App\Actions\Safety\UnblockProfile;
use App\Actions\Safety\UnignoreProfile;
use App\Domain\Chat\ChatFreezer;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\AccessRequestStatus;
use App\Enums\ConversationStatus;
use App\Enums\Entitlement;
use App\Enums\FreezeReason;
use App\Enums\InterestStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Events\Chat\ConversationFrozen;
use App\Events\Safety\ConnectionsChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\AccessRequest;
use App\Models\Block;
use App\Models\Favorite;
use App\Models\Ignore;
use App\Models\Interest;
use App\Models\Like;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P6.2 — Block (silent, symmetric; R-M06-4, R-M03-1) and Ignore (one-way), PRD M09.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

it('R-M06-4: a block removes likes and favourites both ways, withdraws pending interests both ways and gives favourite slots back', function (): void {
    $groom = groom();
    $bride = bride();
    app(ToggleLike::class)->handle($groom, $bride->profile, true);
    app(ToggleLike::class)->handle($bride, $groom->profile, true);
    app(ToggleFavorite::class)->handle($groom, $bride->profile, true);
    app(ToggleFavorite::class)->handle($bride, $groom->profile, true);
    app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
    $entitlements = app(EntitlementService::class);
    expect($entitlements->used($groom->profile, Entitlement::FavoritesTotal))->toBe(1);

    Event::fake([ConnectionsChanged::class]);
    app(BlockProfile::class)->handle($bride, $groom->profile);

    expect(Block::query()->where('blocker_profile_id', $bride->profile->id)->where('blocked_profile_id', $groom->profile->id)->exists())->toBeTrue()
        ->and(Like::query()->count())->toBe(0)
        ->and(Favorite::query()->count())->toBe(0)
        ->and(Interest::query()->sole()->status)->toBe(InterestStatus::Withdrawn);
    $entitlements->forget($groom->profile);
    $entitlements->forget($bride->profile);
    expect($entitlements->used($groom->profile, Entitlement::FavoritesTotal))->toBe(0)
        ->and($entitlements->used($bride->profile, Entitlement::FavoritesTotal))->toBe(0);
});

it('a block declines waiting photo / horoscope requests both ways and deletes bell rows that name the other (R-M08-1)', function (): void {
    $groom = groom();
    $bride = bride();
    $request = AccessRequest::factory()->create(['requester_profile_id' => $groom->profile->id, 'owner_profile_id' => $bride->profile->id]);
    $named = fn (User $to, string $code): string => (string) DB::table('notifications')->insertGetId([
        'id' => (string) Str::uuid(), 'type' => 'profile_viewed', 'notifiable_type' => $to->getMorphClass(), 'notifiable_id' => $to->id,
        'data' => json_encode(['actor_code' => $code, 'title' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $named($bride, $groom->profile->code);
    $named($groom, $bride->profile->code);
    $named($bride, 'OPM00000001');   // about someone else: stays

    app(BlockProfile::class)->handle($bride, $groom->profile);

    expect($request->refresh()->status)->toBe(AccessRequestStatus::Declined)
        ->and(DB::table('notifications')->count())->toBe(1);
});

it('R-M03-1: after a block neither side can see the other (404 both ways); the blocked member is never notified', function (): void {
    $groom = groom();
    $bride = bride();
    Event::fake([ConnectionsChanged::class]);

    app(BlockProfile::class)->handle($bride, $groom->profile);

    $visibility = app(ProfileVisibility::class);
    expect($visibility->canView($groom->profile, $bride))->toBeFalse()
        ->and($visibility->canView($bride->profile, $groom))->toBeFalse();
    $this->actingAs($groom)->get(memberUrl('/profile/'.$bride->profile->code))->assertNotFound();
    $this->actingAs($bride)->get(memberUrl('/profile/'.$groom->profile->code))->assertNotFound();

    Notification::assertNothingSentTo($groom);
    // Live: both sides re-read, with no payload that names anyone.
    Event::assertDispatched(ConnectionsChanged::class, fn (ConnectionsChanged $e): bool => $e->userId === $groom->id && $e->broadcastWith() === []);
    Event::assertDispatched(ConnectionsChanged::class, fn (ConnectionsChanged $e): bool => $e->userId === $bride->id);
});

it('R-M06-4: a block freezes the pair’s open thread live (BLOCK); unblocking opens it again', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    Event::fake([ConversationFrozen::class, ConnectionsChanged::class]);

    app(BlockProfile::class)->handle($groom, $bride->profile);

    expect($thread->refresh()->status)->toBe(ConversationStatus::Frozen)->and($thread->frozen_reason)->toBe(FreezeReason::Block)
        ->and(Interest::query()->sole()->status)->toBe(InterestStatus::Accepted);   // accepted interests stay
    Event::assertDispatched(ConversationFrozen::class, fn (ConversationFrozen $e): bool => $e->conversationId === $thread->id && $e->frozen);

    app(UnblockProfile::class)->handle($groom, $bride->profile);

    expect($thread->refresh()->status)->toBe(ConversationStatus::Open)->and($thread->frozen_reason)->toBeNull()
        ->and(app(ProfileVisibility::class)->canView($bride->profile, $groom))->toBeTrue();
});

it('unblock: only my own block goes; while the other member’s block stands the pair stays hidden and the thread stays frozen', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    app(BlockProfile::class)->handle($groom, $bride->profile);
    // The bride can't see the groom any more, but shares a thread with him — she can block too.
    app(BlockProfile::class)->handle($bride, $groom->profile);

    app(UnblockProfile::class)->handle($groom, $bride->profile);

    expect(Block::query()->count())->toBe(1)
        ->and($thread->refresh()->status)->toBe(ConversationStatus::Frozen)
        ->and(app(ProfileVisibility::class)->canView($bride->profile, $groom))->toBeFalse();
    expect(fn () => app(UnblockProfile::class)->handle($groom, $bride->profile))->toThrow(NotFoundHttpException::class);
});

it('a thread a moderator froze stays frozen after an unblock (the moderator takes the block freeze over)', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    app(BlockProfile::class)->handle($groom, $bride->profile);
    DB::transaction(fn () => app(ChatFreezer::class)->freeze($thread->refresh(), FreezeReason::Moderation));

    app(UnblockProfile::class)->handle($groom, $bride->profile);

    expect($thread->refresh()->status)->toBe(ConversationStatus::Frozen)->and($thread->frozen_reason)->toBe(FreezeReason::Moderation);
});

it('R-M06-1: blocking twice changes nothing and sends nothing the second time', function (): void {
    $groom = groom();
    $bride = bride();
    app(BlockProfile::class)->handle($bride, $groom->profile);
    Event::fake([ConnectionsChanged::class]);

    app(BlockProfile::class)->handle($bride, $groom->profile);

    expect(Block::query()->count())->toBe(1);
    Event::assertNotDispatched(ConnectionsChanged::class);
});

it('security: own profile, an invisible profile, a profile that blocked me, a suspended member → 404; support session refused; nothing written', function (string $case): void {
    $groom = groom();
    $bride = bride();
    $member = $bride;
    $target = $groom->profile;

    match ($case) {
        'self' => $target = $bride->profile,
        'same gender' => $target = bride('+919800000401')->profile,
        'blocked by them' => app(BlockProfile::class)->handle($groom, $bride->profile),
        'suspended' => $bride->forceFill(['status' => UserStatus::Suspended])->save(),
        'support session' => chatImpersonate($bride),
    };
    $before = Block::query()->count();

    expect(fn () => app(BlockProfile::class)->handle($member->refresh(), $target))
        ->toThrow($case === 'support session' ? ImpersonationRestricted::class : NotFoundHttpException::class);
    expect(Block::query()->count())->toBe($before);
})->with(['self', 'same gender', 'blocked by them', 'suspended', 'support session']);

it('rule 10: blocks + ignores are limited per hour (429, nothing written)', function (): void {
    $groom = groom();
    app(IgnoreProfile::class)->handle($groom, reporterNo(1)->profile);   // counts towards the same limit
    foreach (range(2, Settings::int(SettingKey::SafetyBlocksMaxPerHour)) as $ignored) {
        RateLimiter::hit('safety-block:'.$groom->profile->id, 3600);
    }

    expect(fn () => app(BlockProfile::class)->handle($groom, bride('+919800000420')->profile))
        ->toThrow(fn (SafetyRefused $e) => expect($e->getStatusCode())->toBe(429));
    expect(Block::query()->count())->toBe(0);
});

it('M09 ignore: one-way — the ignored profile leaves MY search, I stay visible to them, nobody is told', function (): void {
    $groom = groom();
    $bride = bride();
    Event::fake([ConnectionsChanged::class]);

    app(IgnoreProfile::class)->handle($groom, $bride->profile);
    app(IgnoreProfile::class)->handle($groom, $bride->profile);   // idempotent

    expect(Ignore::query()->count())->toBe(1)
        ->and(app(ProfileVisibility::class)->canView($groom->profile, $bride))->toBeTrue();
    Event::assertNotDispatched(ConnectionsChanged::class);
    Notification::assertNothingSentTo($bride);

    app(UnignoreProfile::class)->handle($groom, $bride->profile);
    expect(Ignore::query()->count())->toBe(0);
    expect(fn () => app(UnignoreProfile::class)->handle($groom, $bride->profile))->toThrow(NotFoundHttpException::class);
});

it('security: ignore needs a profile I can see; another member’s ignore row can’t be removed', function (): void {
    $groom = groom();
    $bride = bride();
    $other = groom('+919800000430');
    app(IgnoreProfile::class)->handle($other, $bride->profile);

    expect(fn () => app(IgnoreProfile::class)->handle($groom, groom('+919800000431')->profile))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(UnignoreProfile::class)->handle($groom, $bride->profile))->toThrow(NotFoundHttpException::class);
    expect(Ignore::query()->count())->toBe(1);
});
