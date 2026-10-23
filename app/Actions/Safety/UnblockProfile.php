<?php

declare(strict_types=1);

namespace App\Actions\Safety;

use App\Domain\Chat\ChatFreezer;
use App\Domain\Engagement\Connections;
use App\Domain\Safety\BlockList;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Take back a block the member made (/settings/blocked, decisions 2026-10-23): only the member's
 * OWN block row goes — a block the other member made stays, and the pair stays hidden then. When
 * no block is left either way, the pair's thread opens again if the block froze it (a thread a
 * moderator froze stays frozen). Nothing the block removed comes back. Both open pages re-read
 * (ConnectionsChanged); nobody is told. No visibility check: a blocked profile is never visible.
 */
final class UnblockProfile
{
    public function __construct(
        private readonly BlockList $blocks,
        private readonly ChatFreezer $freezer,
        private readonly Connections $connections,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException the member has not blocked this profile
     * @throws SafetyRefused too many blocks / ignores this hour
     */
    public function handle(User $member, Profile $target): void
    {
        $this->impersonation->assertAllowed();

        $me = $member->isActive() ? $member->profile : null;
        if ($me === null || ! Block::query()->where('blocker_profile_id', $me->id)->where('blocked_profile_id', $target->id)->exists()) {
            throw new NotFoundHttpException;
        }

        BlockProfile::throttle($this->limiter, $me);

        DB::transaction(function () use ($me, $target): void {
            Profile::withTrashed()->whereKey([$me->id, $target->id])->orderBy('id')->lockForUpdate()->get(['id']);

            Block::query()->where('blocker_profile_id', $me->id)->where('blocked_profile_id', $target->id)->delete();

            if ($this->blocks->isBlockedPair($me->id, $target->id)) {
                return;
            }

            $thread = Conversation::query()->where('pair_key', Conversation::pairKey($me->id, $target->id))->lockForUpdate()->first();
            if ($thread !== null && $thread->status === ConversationStatus::Frozen && $thread->frozen_reason === FreezeReason::Block) {
                $this->freezer->unfreeze($thread);
            }
        });

        $this->connections->forget();
        BlockProfile::tellBoth($me, $target);
    }
}
