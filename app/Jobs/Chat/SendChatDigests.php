<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Enums\ConversationStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Chat\NewMessagesDigest;
use App\Support\Facades\Settings;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The offline chat digest (PRD M07, M08 `new_message`), every 5 minutes. A member gets ONE email
 * listing who wrote, when ALL of this holds:
 *  - they have unread messages in a thread that isn't muted, CLOSED or with a blocked member;
 *  - the oldest of those not yet put in a digest is at least `chat.digest_delay_minutes` (10) old;
 *  - they haven't been active on the site for that long either (`users.last_seen_at`) — an online
 *    member reads their messages instead;
 *  - an active account (NewMessagesDigest then needs email allowed and a verified address).
 * Each waiting message is announced at most once (`digested_up_to_message_id`, moved forward even
 * when no email could go out — the member is told on the site anyway).
 *
 * Cost: the SQL filter keeps only threads with something newer than their digest pointer, so a
 * long-inactive member with old unread messages is not re-read every run. Per chunk of members:
 * one candidate query, one grouped query over the waiting messages, one for the senders' names.
 */
final class SendChatDigests implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const CHUNK = 200;

    public int $timeout = 300;

    public function handle(): void
    {
        $cutoff = now()->subMinutes(Settings::int(SettingKey::ChatDigestDelayMinutes));

        // The ids are read first: digesting moves pointers, which drops rows out of the candidate
        // set, so paging that query by offset would skip members.
        $this->candidates($cutoff)
            ->distinct()->orderBy('conversation_participants.profile_id')
            ->pluck('conversation_participants.profile_id')
            ->map(fn ($id): string => (string) $id)
            ->chunk(self::CHUNK)
            ->each(fn (Collection $ids) => $this->digestChunk($ids->values()->all(), $cutoff));
    }

    /**
     * Participant rows that may have something to announce: unread, not muted, thread not CLOSED,
     * no block between the pair, a message newer than the digest pointer, an active member who has
     * been away since the cutoff.
     *
     * @return Builder<ConversationParticipant>
     */
    private function candidates(DateTimeInterface $cutoff): Builder
    {
        return ConversationParticipant::query()
            ->join('conversations', 'conversations.id', '=', 'conversation_participants.conversation_id')
            ->join('conversation_participants as other', function ($join): void {
                $join->on('other.conversation_id', '=', 'conversation_participants.conversation_id')
                    ->whereColumn('other.profile_id', '!=', 'conversation_participants.profile_id');
            })
            ->join('profiles', 'profiles.id', '=', 'conversation_participants.profile_id')
            ->join('users', 'users.id', '=', 'profiles.user_id')
            ->where('conversation_participants.unread_count', '>', 0)
            ->where('conversation_participants.is_muted', false)
            ->where('conversations.status', '!=', ConversationStatus::Closed->value)
            ->where(fn ($q) => $q->whereNull('conversation_participants.digested_up_to_message_id')
                ->orWhereColumn('conversations.last_message_id', '>', 'conversation_participants.digested_up_to_message_id'))
            ->whereNull('profiles.deleted_at')
            ->where('users.status', UserStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('users.last_seen_at')->orWhere('users.last_seen_at', '<=', $cutoff))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('blocks')
                ->where(fn ($pair) => $pair
                    ->where(fn ($w) => $w->whereColumn('blocks.blocker_profile_id', 'conversation_participants.profile_id')
                        ->whereColumn('blocks.blocked_profile_id', 'other.profile_id'))
                    ->orWhere(fn ($w) => $w->whereColumn('blocks.blocker_profile_id', 'other.profile_id')
                        ->whereColumn('blocks.blocked_profile_id', 'conversation_participants.profile_id'))));
    }

    /** @param  list<string>  $profileIds */
    private function digestChunk(array $profileIds, DateTimeInterface $cutoff): void
    {
        $participants = $this->candidates($cutoff)
            ->whereIn('conversation_participants.profile_id', $profileIds)
            ->get(['conversation_participants.id', 'conversation_participants.profile_id', 'conversations.last_message_id as thread_last_id', 'profiles.user_id']);
        if ($participants->isEmpty()) {
            return;
        }

        // Per thread: the other member's unread messages after the pointer — count, oldest time, newest id.
        $waiting = ConversationParticipant::query()
            ->join('messages', 'messages.conversation_id', '=', 'conversation_participants.conversation_id')
            ->whereIn('conversation_participants.id', $participants->pluck('id')->all())
            ->whereNull('messages.read_at')
            ->whereNotNull('messages.sender_profile_id')
            ->whereColumn('messages.sender_profile_id', '!=', 'conversation_participants.profile_id')
            ->where(fn ($q) => $q->whereNull('conversation_participants.digested_up_to_message_id')
                ->orWhereColumn('messages.id', '>', 'conversation_participants.digested_up_to_message_id'))
            ->groupBy('conversation_participants.id', 'messages.sender_profile_id')
            ->toBase()
            ->get([
                'conversation_participants.id as participant_id', 'messages.sender_profile_id',
                DB::raw('COUNT(*) as waiting'), DB::raw('MIN(messages.created_at) as first_at'), DB::raw('MAX(messages.id) as up_to'),
            ])
            ->keyBy('participant_id');

        $names = Profile::withTrashed()->whereKey($waiting->pluck('sender_profile_id')->unique()->all())->pluck('first_name', 'id');
        $users = User::query()->whereKey($participants->pluck('user_id')->unique()->all())->get()->keyBy('id');

        foreach ($participants->groupBy('profile_id') as $rows) {
            $threads = [];
            $advance = [];
            foreach ($rows as $participant) {
                $row = $waiting->get((string) $participant->id);
                if ($row === null && $participant->getAttribute('thread_last_id') !== null) {
                    // Nothing of theirs waits up to the thread's newest message: move the pointer so
                    // this thread isn't re-read until someone writes again.
                    $advance[(string) $participant->id] = (string) $participant->getAttribute('thread_last_id');

                    continue;
                }
                if ($row === null || Carbon::parse((string) $row->first_at)->gt($cutoff)) {
                    continue; // not 10 minutes old yet — the next run will look again
                }
                $threads[] = ['name' => (string) $names->get((string) $row->sender_profile_id), 'count' => (int) $row->waiting];
                $advance[(string) $participant->id] = (string) $row->up_to;
            }

            foreach ($advance as $participantId => $upTo) {
                ConversationParticipant::query()->whereKey($participantId)->update(['digested_up_to_message_id' => $upTo]);
            }

            $user = $users->get((string) $rows->first()?->getAttribute('user_id'));
            if ($threads !== [] && $user instanceof User) {
                $user->notify(new NewMessagesDigest($threads));
            }
        }
    }
}
