<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Domain\Safety\BlockList;
use App\Enums\ConversationStatus;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * The member's unread chat messages across threads (PRD M07 `Shared\ChatBadge`, tab title): the
 * sum of their participants' `unread_count`, leaving out CLOSED threads and threads with a member
 * they may not see (a block either way) — the same threads the inbox lists. Two queries.
 */
final class UnreadChats
{
    public function __construct(private readonly BlockList $blocks) {}

    public function total(Profile $profile): int
    {
        $hidden = $this->blocks->hiddenFrom($profile);

        return (int) ConversationParticipant::query()
            ->from('conversation_participants as me')
            ->join('conversations as c', 'c.id', '=', 'me.conversation_id')
            ->where('me.profile_id', $profile->id)
            ->where('me.unread_count', '>', 0)
            ->where('c.status', '!=', ConversationStatus::Closed->value)
            ->when($hidden !== [], fn ($q) => $q->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('conversation_participants as them')
                ->whereColumn('them.conversation_id', 'me.conversation_id')
                ->whereIn('them.profile_id', $hidden)))
            ->sum('me.unread_count');
    }
}
