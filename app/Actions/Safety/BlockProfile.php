<?php

declare(strict_types=1);

namespace App\Actions\Safety;

use App\Domain\Chat\ChatFreezer;
use App\Domain\Engagement\Connections;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\AccessRequestStatus;
use App\Enums\Entitlement;
use App\Enums\FreezeReason;
use App\Enums\InterestStatus;
use App\Enums\SettingKey;
use App\Events\Safety\ConnectionsChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\AccessRequest;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Favorite;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Like;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Block another member (PRD M09 "Block (silent, symmetric)", R-M06-4, R-M03-1). In one
 * transaction, with both profile rows locked (id order — two members blocking each other can't
 * deadlock):
 *  - the block row (a repeat changes nothing);
 *  - likes both ways deleted; favourites both ways deleted and their slots given back (daily
 *    likes are not); PENDING interests both ways WITHDRAWN (no quota back);
 *  - PENDING photo / horoscope requests both ways DECLINED; both members' notification rows that
 *    name the other deleted;
 *  - the pair's OPEN thread FROZEN (FreezeReason::Block) — accepted interests stay.
 * After commit both members' open pages re-read (ConnectionsChanged, no payload) and the thread
 * locks live (ConversationFrozen). Nobody is told: no notification, nothing names the blocker.
 * The member must be able to see the profile (else 404) — or share a thread with it. Blocks +
 * ignores are limited by `safety.blocks_max_per_hour`. Never during an admin support session.
 */
final class BlockProfile
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly ChatFreezer $freezer,
        private readonly EntitlementService $entitlements,
        private readonly Connections $connections,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException not a profile this member can block
     * @throws SafetyRefused too many blocks / ignores this hour
     */
    public function handle(User $member, Profile $target): void
    {
        $this->impersonation->assertAllowed();

        $me = $member->isActive() ? $member->profile : null;
        if ($me === null || $me->id === $target->id) {
            throw new NotFoundHttpException;
        }

        if (Block::query()->where('blocker_profile_id', $me->id)->where('blocked_profile_id', $target->id)->exists()) {
            return;   // R-M06-1: idempotent
        }

        // A profile I can't see (hidden, or one that blocked me) may still be blocked when we share a
        // thread — the answer is the same whether or not they blocked me, so it reveals nothing.
        $thread = Conversation::query()->where('pair_key', Conversation::pairKey($me->id, $target->id))->exists();
        if (! $thread && ! $this->visibility->canView($target, $member)) {
            throw new NotFoundHttpException;
        }

        self::throttle($this->limiter, $me);

        $blocked = DB::transaction(function () use ($me, $target): bool {
            Profile::withTrashed()->whereKey([$me->id, $target->id])->orderBy('id')->lockForUpdate()->get(['id']);

            $inserted = Block::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'blocker_profile_id' => $me->id,
                'blocked_profile_id' => $target->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return false;
            }

            Like::query()->where(fn ($q) => $q->where('from_profile_id', $me->id)->where('to_profile_id', $target->id))
                ->orWhere(fn ($q) => $q->where('from_profile_id', $target->id)->where('to_profile_id', $me->id))
                ->delete();

            foreach ([[$me, $target], [$target, $me]] as [$owner, $other]) {
                if (Favorite::query()->where('profile_id', $owner->id)->where('favorite_profile_id', $other->id)->delete() > 0) {
                    $this->entitlements->release($owner, Entitlement::FavoritesTotal);
                }
            }

            $pending = Interest::query()->where('status', InterestStatus::Pending->value)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('from_profile_id', $me->id)->where('to_profile_id', $target->id))
                    ->orWhere(fn ($q) => $q->where('from_profile_id', $target->id)->where('to_profile_id', $me->id)))
                ->lockForUpdate()->get();
            foreach ($pending as $interest) {
                $interest->status = InterestStatus::Withdrawn;
                $interest->responded_at = now();
                $interest->save();
                InterestEvent::record($interest, InterestStatus::Withdrawn, $me->id);
            }

            // Waiting photo / horoscope requests between them close quietly (decisions 2026-10-23).
            AccessRequest::query()->where('status', AccessRequestStatus::Pending->value)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('requester_profile_id', $me->id)->where('owner_profile_id', $target->id))
                    ->orWhere(fn ($q) => $q->where('requester_profile_id', $target->id)->where('owner_profile_id', $me->id)))
                ->update(['status' => AccessRequestStatus::Declined->value, 'responded_at' => now(), 'updated_at' => now()]);

            // Bell rows that name the other member ("<name> viewed your profile") go too (R-M08-1).
            foreach ([[$me, $target], [$target, $me]] as [$owner, $other]) {
                if ($owner->user_id !== null) {
                    DB::table('notifications')->where('notifiable_id', $owner->user_id)->where('data->actor_code', $other->code)->delete();
                }
            }

            $thread = Conversation::query()->where('pair_key', Conversation::pairKey($me->id, $target->id))->lockForUpdate()->first();
            if ($thread !== null) {
                $this->freezer->freeze($thread, FreezeReason::Block);
            }

            return true;
        });

        if ($blocked) {
            $this->connections->forget();
            self::tellBoth($me, $target);
        }
    }

    /** `safety.blocks_max_per_hour` — shared by block, unblock, ignore and un-ignore. */
    public static function throttle(RateLimiter $limiter, Profile $me): void
    {
        $key = 'safety-block:'.$me->id;
        if ($limiter->tooManyAttempts($key, Settings::int(SettingKey::SafetyBlocksMaxPerHour))) {
            throw SafetyRefused::tooMany();
        }
        $limiter->hit($key, 3600);
    }

    /** Both open pages re-read (ConnectionsChanged leaves after the commit). */
    public static function tellBoth(Profile $a, Profile $b): void
    {
        foreach ([$a, $b] as $profile) {
            if ($profile->user_id !== null) {
                ConnectionsChanged::dispatch($profile->user_id);
            }
        }
    }
}
