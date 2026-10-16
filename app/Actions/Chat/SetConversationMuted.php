<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\ChatAccess;
use App\Domain\Chat\InboxSignals;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mute / unmute the member's thread with this profile (PRD M07 "mute"; P4.2). A muted thread still
 * counts its unread messages and still re-orders the inbox, but pops no toast and is left out of
 * the offline email digest. Only the member's own side changes; the other member sees nothing.
 * Idempotent. Never during an admin support session.
 */
final class SetConversationMuted
{
    public function __construct(
        private readonly ChatAccess $access,
        private readonly InboxSignals $inbox,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException no such thread for this member
     */
    public function handle(User $member, Profile $other, bool $muted): void
    {
        $this->impersonation->assertAllowed();

        $me = $member->profile ?? throw new NotFoundHttpException;
        $thread = $this->access->threadWith($me, $other) ?? throw new NotFoundHttpException;

        $changed = ConversationParticipant::query()->where('conversation_id', $thread->id)->where('profile_id', $me->id)
            ->where('is_muted', ! $muted)->update(['is_muted' => $muted, 'updated_at' => now()]);

        if ($changed > 0) {
            $this->inbox->changed($thread, [$me->id]);
        }
    }
}
