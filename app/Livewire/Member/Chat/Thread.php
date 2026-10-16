<?php

declare(strict_types=1);

namespace App\Livewire\Member\Chat;

use App\Actions\Chat\MarkConversationRead;
use App\Actions\Chat\SendMessage;
use App\Data\Chat\MessageData;
use App\Domain\Chat\ChatAccess;
use App\Domain\Chat\ReadReceipts;
use App\Domain\Media\PhotoUrls;
use App\Domain\Profile\ProfileNames;
use App\Enums\ChatSendState;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Chat\ChatRefused;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Chat\ThreadWindow;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The open thread (PRD M07, F03, template messages.php centre): the other member's header, the
 * newest messages with day separators and same-sender grouping, ticks on the member's own
 * messages, and the composer. The composer (Alpine, resources/js/alpine/chat.js) shows the
 * message at once as a grey bubble with its own client id and calls send(); the re-render brings
 * the stored message and the bubble goes. A refusal marks the bubble "not sent" with Retry (the
 * same client id, so a retry never doubles it).
 *
 * Live on `chat.{conversation}`: a new message, delivered / read receipts → re-read; a message from
 * the other member while the tab is visible marks the thread read. A reconnect re-reads.
 * The composer is locked with the reason when ChatAccess says the member may not write (Free
 * member: "Upgrade to chat" — R-M07-3; the server refuses a bypass with 403).
 * Admin support session: no message text at all (R-M07-6, owner decision 2026-10-15), nothing sent
 * or marked read.
 */
final class Thread extends Component
{
    #[Locked]
    public string $withCode = '';

    /** Resolved once per request (never sent to the browser). */
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
            'realtime-reconnected' => '$refresh',
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

    public function send(string $clientId, string $body, SendMessage $send): void
    {
        try {
            $send->handle($this->member(), $this->other(), $body, $clientId);
        } catch (ChatRefused|ImpersonationRestricted $refused) {
            $this->failed($clientId, $refused->getMessage());

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

    public function render(ChatAccess $access, ThreadWindow $window, ReadReceipts $receipts, ProfileNames $names, PhotoUrls $photos): View
    {
        $thread = $this->thread();
        $me = $this->me();
        $other = $this->other();
        $support = $this->support();
        $state = $support ? ChatSendState::Unavailable : $access->sendState($me, $other, $thread);

        return view('livewire.member.chat.thread', [
            'threadId' => $thread->id,
            'myCode' => $me->code,
            'name' => $names->forViewer($other, $this->member()),
            'code' => $other->code,
            'photoUrl' => $photos->primaryCardUrl($other, $this->member()) ?? PhotoUrls::PLACEHOLDER,
            'profileUrl' => $other->trashed() ? null : route('member.profile.show', $other->code),
            'days' => $support ? [] : $this->days($window->latest($thread, $me, $receipts->shownBetween($me->id, $other->id))),
            'support' => $support,
            'state' => $state,
            'freeLeft' => $state->canSend() && $access->sendsFree($me) ? $access->freeRepliesLeft($me, $other, $thread) : null,
            'upgradable' => in_array($state, [ChatSendState::Upgrade, ChatSendState::WaitForReply], true),
            'maxLength' => Settings::int(SettingKey::ChatMaxLength),
            'plansUrl' => route('plans'),
        ]);
    }

    /**
     * Messages by IST day ("Today", "Yesterday", "12 Oct 2026"); `grouped` = same sender as the
     * message before within 5 minutes (the time is shown once for the run).
     *
     * @param  list<MessageData>  $messages
     * @return list<array{label: string, messages: list<array{message: MessageData, grouped: bool}>}>
     */
    private function days(array $messages): array
    {
        $now = CarbonImmutable::now((string) config('oppam.display_timezone'));
        $days = [];
        $previous = null;

        foreach ($messages as $message) {
            $key = $message->at->toDateString();
            if (! isset($days[$key])) {
                $days[$key] = ['label' => match (true) {
                    $message->at->isSameDay($now) => __('Today'),
                    $message->at->isSameDay($now->subDay()) => __('Yesterday'),
                    default => $message->at->translatedFormat('j M Y'),
                }, 'messages' => []];
                $previous = null;
            }

            $grouped = $previous !== null && ! $message->system && ! $previous->system && $previous->mine === $message->mine
                && $message->at->diffInMinutes($previous->at, true) < 5;
            $days[$key]['messages'][] = ['message' => $message, 'grouped' => $grouped];
            $previous = $message;
        }

        return array_values($days);
    }

    private function failed(string $clientId, string $reason): void
    {
        $this->dispatch('chat-send-failed', clientId: $clientId, message: $reason);
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
