<?php

declare(strict_types=1);

namespace App\Livewire\Member\Chat;

use App\Actions\Chat\MarkConversationRead;
use App\Actions\Chat\ReportChatMessages;
use App\Actions\Chat\SendIcebreaker;
use App\Actions\Chat\SendMessage;
use App\Actions\Chat\SetConversationMuted;
use App\Actions\Chat\UnsendMessage;
use App\Data\Chat\ThreadDays;
use App\Domain\Chat\ChatAccess;
use App\Domain\Chat\LastSeen;
use App\Domain\Chat\ReadReceipts;
use App\Domain\Media\PhotoUrls;
use App\Domain\Profile\ProfileNames;
use App\Enums\ChatSendState;
use App\Enums\ReportReason;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Chat\ChatRefused;
use App\Exceptions\Safety\ReportRefused;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Chat\ThreadWindow;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The open thread (PRD M07, F03, template messages.php centre). Header: the other member, "Online
 * now" (live roster) or "Last seen …" (their privacy allowing), typing, mute. Messages by id
 * cursor (ThreadWindow): the newest 30, "load earlier" moves the cursor back 30, and every
 * re-render re-reads everything newer — so a socket reconnect back-fills in order (§9.7.1).
 * The composer (Alpine, resources/js/alpine/chat.js) shows a message at once with its own client
 * id and calls send() / sendImage() / icebreaker(); the re-render brings the stored message. A
 * refusal marks the bubble "not sent" with Retry (same client id — never stored twice). Own
 * messages can be unsent within the window. Live on `chat.{conversation}`: new / delivered / read /
 * deleted messages re-read; a message from the other member while the tab is in view marks it read.
 * The composer is locked with the reason when ChatAccess says the member may not write; a freeze
 * (`.conversation.frozen`, R-M07-5) re-reads it live. Report (M09): the member picks a reason and
 * some of the other member's messages on screen; ReportChatMessages checks every id again.
 * Admin support session: no message text at all (R-M07-6), nothing sent, read, unsent or muted.
 */
final class Thread extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $withCode = '';

    /** Id cursor: the oldest message on screen (null until the first render). */
    #[Locked]
    public ?string $fromId = null;

    /** The report dialog (M09): reason, optional details, the other member's message ids picked. */
    public string $reportReason = '';

    public string $reportDetails = '';

    /** @var list<string> */
    public array $reportMessages = [];

    /** The photo being sent (Livewire temporary upload); validated by SendMessage::image(). */
    public ?TemporaryUploadedFile $photo = null;

    private ?Conversation $resolvedThread = null;

    private ?Profile $resolvedOther = null;

    public function mount(string $withCode): void
    {
        $this->withCode = $withCode;
        $this->thread();   // 404 for a thread this member has no access to

        if (! $this->support()) {
            app(MarkConversationRead::class)->handle($this->member(), $this->other());
        }
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        if ($this->support()) {   // ChatChannel refuses a support session
            return [];
        }

        $channel = 'echo-private:chat.'.$this->thread()->id;

        return [
            $channel.',.message.sent' => 'onMessage',
            $channel.',.message.delivered' => '$refresh',
            $channel.',.message.read' => '$refresh',
            $channel.',.message.deleted' => '$refresh',
            $channel.',.conversation.frozen' => '$refresh',   // R-M07-5: the composer locks / unlocks live
            // F04: a payment activated a plan — the "Upgrade to chat" composer unlocks without a reload.
            'echo-private:App.Models.User.'.$this->member()->id.',.entitlements.changed' => '$refresh',
            'realtime-reconnected' => '$refresh',   // back-fill: the window re-reads everything newer
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public function onMessage(array $payload = []): void
    {
        if (($payload['sender_code'] ?? null) !== $this->me()->code) {
            $this->dispatch('chat-message-arrived');   // the composer marks read when the tab is in view
        }
    }

    public function markRead(MarkConversationRead $read): void
    {
        if ($this->support()) {
            $this->skipRender();

            return;
        }

        if ($read->handle($this->member(), $this->other()) > 0) {
            $this->dispatch('chat-inbox-changed');
        }
    }

    public function loadEarlier(ThreadWindow $window): void
    {
        if ($this->fromId !== null && ! $this->support()) {
            $this->fromId = $window->earlierCursor($this->thread(), $this->fromId) ?? $this->fromId;
        }
        $this->dispatch('chat-loaded-earlier');
    }

    public function send(string $clientId, string $body, SendMessage $send): void
    {
        $this->attempt($clientId, function () use ($send, $body, $clientId): void {
            $message = $send->handle($this->member(), $this->other(), $body, $clientId);
            if ($message->body !== trim($body)) {   // ContentSafety masked something (first messages)
                $this->dispatch('toast', type: 'info', title: __('To keep you safe, phone numbers, emails and links are hidden in your first messages.'));
            }
        });
    }

    public function sendImage(string $clientId, SendMessage $send): void
    {
        $photo = $this->photo;
        $this->attempt($clientId, fn () => $photo instanceof TemporaryUploadedFile
            ? $send->image($this->member(), $this->other(), $photo, $clientId)
            : throw ValidationException::withMessages(['photo' => __('Please choose a photo.')]));
        $this->reset('photo');
    }

    public function icebreaker(string $clientId, string $code, SendIcebreaker $icebreakers): void
    {
        $this->attempt($clientId, fn () => $icebreakers->handle($this->member(), $this->other(), $code, $clientId));
    }

    public function unsend(string $messageId, UnsendMessage $unsend): void
    {
        try {
            $unsend->handle($this->member(), $messageId);
        } catch (ChatRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', title: $refused->getMessage(), type: 'danger');
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', title: __('This message is no longer available.'), type: 'danger');
        }
    }

    public function setMuted(bool $muted, SetConversationMuted $mute): void
    {
        try {
            $mute->handle($this->member(), $this->other(), $muted);
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return;
        }

        $this->dispatch('toast', type: 'success', title: $muted ? __('Conversation muted.') : __('Conversation unmuted.'));
        $this->dispatch('chat-inbox-changed');
    }

    public function report(ReportChatMessages $report): void
    {
        try {
            $report->handle($this->member(), $this->other(), $this->reportReason, $this->reportDetails,
                array_values(array_filter($this->reportMessages, 'is_string')));
        } catch (ReportRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('reportReason', 'reportDetails', 'reportMessages');
        $this->dispatch('close-modal', name: 'report-chat');
        $this->dispatch('toast', type: 'success', title: __('Thank you for telling us. Our safety team will review it — :name won’t be told who reported.', ['name' => $this->other()->first_name]));
    }

    public function render(ChatAccess $access, ThreadWindow $window, ReadReceipts $receipts, ProfileNames $names, PhotoUrls $photos, SendIcebreaker $icebreakers, LastSeen $lastSeen): View
    {
        $thread = $this->thread();
        $me = $this->me();
        $other = $this->other();
        $support = $this->support();
        $state = $support ? ChatSendState::Unavailable : $access->sendState($me, $other, $thread);
        $page = $support ? ['messages' => [], 'oldestId' => null, 'hasEarlier' => false]
            : $window->window($thread, $me, $receipts->shownBetween($me->id, $other->id), $this->fromId);
        $this->fromId ??= $page['oldestId'];

        return view('livewire.member.chat.thread', [
            'threadId' => $thread->id,
            'myCode' => $me->code,
            'name' => $names->forViewer($other, $this->member()),
            'firstName' => $other->first_name,
            'code' => $other->code,
            'photoUrl' => $photos->primaryCardUrl($other, $this->member()) ?? PhotoUrls::PLACEHOLDER,
            'profileUrl' => $other->trashed() ? null : route('member.profile.show', $other->code),
            'lastSeen' => $support ? null : $lastSeen->label($other),
            'muted' => (bool) ConversationParticipant::query()->where('conversation_id', $thread->id)->where('profile_id', $me->id)->value('is_muted'),
            'days' => ThreadDays::of($page['messages']),
            'hasEarlier' => $page['hasEarlier'],
            'support' => $support,
            'state' => $state,
            'freeLeft' => $state->canSend() && $access->sendsFree($me) ? $access->freeRepliesLeft($me, $other, $thread) : null,
            'canSendPhotos' => $state->canSend() && ! $access->sendsFree($me),
            'icebreakers' => $state->canSend() && ! $icebreakers->hasWritten($me, $thread->id) ? $icebreakers->options() : [],
            'upgradable' => in_array($state, [ChatSendState::Upgrade, ChatSendState::WaitForReply], true),
            'maxLength' => Settings::int(SettingKey::ChatMaxLength),
            'imageMaxMb' => Settings::int(SettingKey::ChatImageMaxMb),
            'plansUrl' => route('plans'),
            // The report dialog lists the other member's messages on screen (newest last, at most 20).
            'reportable' => $support ? [] : collect($page['messages'])
                ->filter(fn ($message): bool => ! $message->mine && ! $message->system && ! $message->deleted)
                ->take(-20)->values()->all(),
            'reportReasons' => ReportReason::options(),
        ]);
    }

    /** Run one send; a refusal marks the composer's bubble "not sent" with the reason. */
    private function attempt(string $clientId, callable $send): void
    {
        try {
            $send();
        } catch (ChatRefused|ImpersonationRestricted $refused) {
            // A safety rule's refusal: the member edits the text rather than retrying it unchanged.
            $this->failed($clientId, $refused->getMessage(), $refused instanceof ChatRefused && $refused->reason === ChatRefused::BLOCKED);

            return;
        } catch (ValidationException $invalid) {
            $this->failed($clientId, (string) collect($invalid->errors())->flatten()->first());

            return;
        } catch (NotFoundHttpException) {
            $this->failed($clientId, __('This conversation is no longer available.'));

            return;
        }

        $this->dispatch('chat-inbox-changed');
    }

    private function failed(string $clientId, string $reason, bool $editable = false): void
    {
        $this->dispatch('chat-send-failed', clientId: $clientId, message: $reason, editable: $editable);
    }

    private function thread(): Conversation
    {
        return $this->resolvedThread ??= app(ChatAccess::class)->threadWith($this->me(), $this->other()) ?? abort(404);
    }

    private function other(): Profile
    {
        if ($this->resolvedOther === null) {
            $other = Messages::validCode($this->withCode) !== '' ? Profile::withTrashed()->where('code', $this->withCode)->first() : null;
            $this->resolvedOther = $other ?? abort(404);
        }

        return $this->resolvedOther;
    }

    private function support(): bool
    {
        return app(Impersonation::class)->isActive();
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }

    private function me(): Profile
    {
        return $this->member()->profile ?? abort(404);
    }
}
