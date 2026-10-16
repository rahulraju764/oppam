<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\ChatAccess;
use App\Domain\Chat\InboxSignals;
use App\Domain\Chat\ReadReceipts;
use App\Events\Chat\MessagesDelivered;
use App\Events\Chat\MessagesRead;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Realtime\LiveSignal;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The member has the thread with this profile open and in view (PRD M07, F03): everything the
 * other member wrote is read — their messages get `read_at` (and `delivered_at` if missing), the
 * member's unread count goes to 0 and their read position to the newest message. After commit:
 * the member's inboxes / badges in other tabs sync (ConversationUpdated), and the other member's
 * ticks turn blue (MessagesRead) — only when the reader has read receipts on (R-M07-7); with them
 * off the other member is only told the messages were delivered.
 * Nothing to mark → nothing written or sent. Never during an admin support session (reading as
 * the member would tell the other member they read it).
 *
 * @return int messages newly marked read
 */
final class MarkConversationRead
{
    public function __construct(
        private readonly ChatAccess $access,
        private readonly InboxSignals $inbox,
        private readonly ReadReceipts $receipts,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException no such thread for this member
     */
    public function handle(User $member, Profile $other): int
    {
        $this->impersonation->assertAllowed();

        $me = $member->profile ?? throw new NotFoundHttpException;
        $thread = $this->access->threadWith($me, $other) ?? throw new NotFoundHttpException;

        return DB::transaction(function () use ($thread, $me, $other): int {
            $mine = ConversationParticipant::query()->where('conversation_id', $thread->id)->where('profile_id', $me->id)->lockForUpdate()->firstOrFail();
            $latest = Message::query()->where('conversation_id', $thread->id)->max('id');

            $now = now();
            $unread = fn () => Message::query()->where('conversation_id', $thread->id)->where('sender_profile_id', $other->id)->whereNull('read_at');
            $unread()->whereNull('delivered_at')->update(['delivered_at' => $now]);
            $marked = $unread()->update(['read_at' => $now]);

            if ($marked === 0 && $mine->unread_count === 0 && $mine->last_read_message_id === $latest) {
                return 0;
            }

            $mine->unread_count = 0;
            $mine->last_read_message_id = is_string($latest) ? $latest : null;
            $mine->save();

            $this->inbox->changed($thread, [$me->id]);

            if ($marked > 0 && is_string($latest)) {
                // Receipts off: the other side only learns the messages arrived (✓✓), never that they were read.
                LiveSignal::afterCommit($this->receipts->enabled($me->id)
                    ? new MessagesRead($thread->id, $me->code, $latest, $now->toIso8601String())
                    : new MessagesDelivered($thread->id, $latest));
            }

            return $marked;
        });
    }
}
