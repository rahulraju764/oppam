<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Domain\Engagement\InterestSignals;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\ConversationStatus;
use App\Enums\InterestStatus;
use App\Enums\MessageType;
use App\Enums\NotificationEvent;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\InterestNotification;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accept an interest the member received (PRD M06, F02) — free on every plan (§7.3). Only the
 * member it was sent to can accept it, only while it is PENDING and not past its expiry, and only
 * if the two may still see each other (a block since → 404).
 *
 * In one transaction: the interest becomes ACCEPTED, the pair's conversation is created (one per
 * pair — `pair_key`; a thread the pair already has is linked to this interest as it is, never
 * duplicated and never un-frozen here — P6.2 owns the frozen / closed rules)
 * with both participants and the SYSTEM message "You are now connected". From then on the two are
 * CONNECTED (Connections): full names, the Chat button, ACCEPTED_ONLY photos / phone / horoscope.
 * The sender is notified; both open pages are told live.
 */
final class AcceptInterest
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly InterestSignals $signals,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  Profile  $from  the member who sent the interest
     *
     * @throws NotFoundHttpException no pending interest from that profile to the member, or the pair may not see each other
     * @throws EngagementRefused answered, withdrawn or expired meanwhile
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $from): Conversation
    {
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        if ($own === null || ! $this->visibility->canView($from, $member)) {
            throw new NotFoundHttpException;
        }

        return $this->accept($own, $from);
    }

    /**
     * The acceptance itself, for a profile whose caller already checked who is acting and that
     * the pair may see each other — the member above, or a bureau for its managed client
     * (RespondToInterestForClient, §11A B.6).
     *
     * @throws NotFoundHttpException no interest from that profile to $own
     * @throws EngagementRefused answered, withdrawn or expired meanwhile
     */
    public function accept(Profile $own, Profile $from): Conversation
    {
        $conversation = DB::transaction(function () use ($own, $from): Conversation {
            $interest = Interest::query()->where('from_profile_id', $from->id)->where('to_profile_id', $own->id)->lockForUpdate()->first();

            if ($interest === null) {
                throw new NotFoundHttpException;
            }

            if ($interest->status !== InterestStatus::Pending || $interest->expires_at->isPast()) {
                throw EngagementRefused::notPending();
            }

            $interest->status = InterestStatus::Accepted;
            $interest->responded_at = now();
            $interest->save();
            InterestEvent::record($interest, InterestStatus::Accepted, $own->id);

            return $this->openConversation($interest, $own, $from);
        }, attempts: 3);   // a deadlock on the pair's new conversation row is retried, not shown as an error

        $this->signals->changed($own, $from);

        if ($from->user_id !== null) {
            User::query()->find($from->user_id)?->notify(new InterestNotification(NotificationEvent::InterestAccepted, $own->id));
        }

        return $conversation;
    }

    private function openConversation(Interest $interest, Profile $own, Profile $from): Conversation
    {
        $pairKey = Conversation::pairKey($own->id, $from->id);
        $conversation = Conversation::query()->where('pair_key', $pairKey)->lockForUpdate()->first();

        if ($conversation !== null) {
            $conversation->interest_id = $interest->id;
            $conversation->save();

            return $conversation;
        }

        $conversation = new Conversation;
        $conversation->pair_key = $pairKey;
        $conversation->interest_id = $interest->id;
        $conversation->status = ConversationStatus::Open;
        $conversation->save();

        foreach ([$own, $from] as $profile) {
            $participant = new ConversationParticipant;
            $participant->conversation_id = $conversation->id;
            $participant->profile_id = $profile->id;
            $participant->save();
        }

        $message = new Message;
        $message->conversation_id = $conversation->id;
        $message->client_id = (string) Str::uuid();
        $message->type = MessageType::System;
        $message->body = 'You are now connected';   // shown translated by the chat view (P4.1)
        $message->save();

        $conversation->last_message_id = $message->id;
        $conversation->last_message_at = now();
        $conversation->save();

        return $conversation;
    }
}
