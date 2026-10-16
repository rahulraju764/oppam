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
 * Archive / unarchive the member's thread with this profile (PRD M07 inbox filters All / Unread /
 * Archived; owner decision 2026-10-15: archive in P4.1, mute in P4.2). Only the member's own side
 * changes; the other member sees nothing. A new message from the other member brings the thread
 * back (SendMessage). Idempotent. Never during an admin support session.
 */
final class SetConversationArchived
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
    public function handle(User $member, Profile $other, bool $archived): void
    {
        $this->impersonation->assertAllowed();

        $me = $member->profile ?? throw new NotFoundHttpException;
        $thread = $this->access->threadWith($me, $other) ?? throw new NotFoundHttpException;

        $changed = ConversationParticipant::query()->where('conversation_id', $thread->id)->where('profile_id', $me->id)
            ->where('is_archived', ! $archived)->update(['is_archived' => $archived, 'updated_at' => now()]);

        if ($changed > 0) {
            $this->inbox->changed($thread, [$me->id]);
        }
    }
}
