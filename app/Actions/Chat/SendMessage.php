<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\ChatAccess;
use App\Domain\Chat\InboxSignals;
use App\Enums\MessageType;
use App\Enums\SettingKey;
use App\Events\Chat\MessageSent;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Chat\ChatRefused;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use App\Support\Realtime\LiveSignal;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Send a chat message (PRD M07 send flow, F03). The member writes to the profile with this code:
 *  - an active account, and the thread must exist for them (participant, not CLOSED, no block
 *    either way) — else 404;
 *  - `client_id` (a UUID made by the composer) makes the send idempotent: the same id again
 *    returns the message already stored, nothing is written or announced twice (§9.7.3);
 *  - body: 1 – `chat.max_length` characters after trimming (R-M07-4);
 *  - `chat.max_per_minute` (30) per member — then 429;
 *  - ChatAccess::sendState must allow it: OPEN, the other member still there and connected,
 *    a paid plan or the Free member's one reply (R-M07-2, R-M07-3) — else 403 / 409.
 * The decision is taken again under a lock on the thread row, so two tabs can't both spend the
 * one free reply. In one transaction: the message, the thread's last message, the recipient's
 * unread count (and their archive is undone: a new message is never hidden). After commit:
 * MessageSent on the thread, ConversationUpdated to both inboxes. Content safety (masking contact
 * details, profanity) plugs in here in P4.3. Never while an admin is impersonating the member.
 */
final class SendMessage
{
    public function __construct(
        private readonly ChatAccess $access,
        private readonly InboxSignals $inbox,
        private readonly Impersonation $impersonation,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException no such thread for this member
     * @throws ValidationException empty / too long body, malformed client id
     * @throws ChatRefused may not write now, or too fast
     */
    public function handle(User $member, Profile $other, string $body, string $clientId): Message
    {
        $this->impersonation->assertAllowed();

        $me = $member->isActive() ? $member->profile : null;
        $thread = $me === null ? null : $this->access->threadWith($me, $other);
        if ($me === null || $thread === null) {
            throw new NotFoundHttpException;
        }

        $body = trim($body);
        Validator::make(['body' => $body, 'client_id' => $clientId], [
            'body' => ['required', 'string', 'max:'.Settings::int(SettingKey::ChatMaxLength)],
            'client_id' => ['required', 'uuid'],
        ])->validate();

        $existing = $this->existing($thread, $me, $clientId);
        if ($existing !== null) {
            return $existing;
        }

        $key = 'chat-send:'.$me->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::ChatMaxPerMinute))) {
            throw ChatRefused::tooFast();
        }

        $message = DB::transaction(function () use ($thread, $me, $other, $body, $clientId): Message {
            $locked = Conversation::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();

            $existing = $this->existing($locked, $me, $clientId);
            if ($existing !== null) {
                return $existing;
            }

            $state = $this->access->sendState($me, $other, $locked);
            if (! $state->canSend()) {
                throw ChatRefused::because($state);
            }

            $message = new Message;
            $message->conversation_id = $locked->id;
            $message->sender_profile_id = $me->id;
            $message->client_id = $clientId;
            $message->type = MessageType::Text;
            $message->body = $body;
            $message->save();

            $locked->last_message_id = $message->id;
            $locked->last_message_at = $message->created_at;
            $locked->save();

            ConversationParticipant::query()->where('conversation_id', $locked->id)->where('profile_id', $other->id)
                ->update(['unread_count' => DB::raw('unread_count + 1'), 'is_archived' => false, 'updated_at' => now()]);
            ConversationParticipant::query()->where('conversation_id', $locked->id)->where('profile_id', $me->id)
                ->update(['last_read_message_id' => $message->id, 'updated_at' => now()]);

            LiveSignal::afterCommit(new MessageSent(
                conversationId: $locked->id,
                messageId: $message->id,
                clientId: $clientId,
                senderProfileId: $me->id,
                recipientProfileId: $other->id,
                senderCode: $me->code,
                type: $message->type->value,
                body: $body,
                createdAt: (string) $message->created_at?->toIso8601String(),
            ));
            $this->inbox->changed($locked, [$other->id, $me->id]);

            return $message;
        });

        if ($message->wasRecentlyCreated) {
            $this->limiter->hit($key, 60);
        }

        return $message;
    }

    /** The member's own message with this client id in this thread (a retry / double click). */
    private function existing(Conversation $thread, Profile $me, string $clientId): ?Message
    {
        $message = Message::query()->where('conversation_id', $thread->id)->where('client_id', $clientId)->first();

        if ($message !== null && $message->sender_profile_id !== $me->id) {
            throw ValidationException::withMessages(['client_id' => __('Please try sending again.')]);
        }

        return $message;
    }
}
