<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Chat;

use App\Actions\Admin\Chat\DecideMessageFlag;
use App\Actions\Admin\Chat\ReopenReportFrozenThreads;
use App\Actions\Admin\Chat\RevealFlaggedMessage;
use App\Actions\Admin\Chat\SetConversationFrozen;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Enums\MessageFlagDecision;
use App\Enums\MessageFlagStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Chat\MessageFlagUnavailable;
use App\Models\AdminUser;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\MessageFlag;
use App\Models\Profile;
use App\Queries\Chat\FlagContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Review one flagged chat message (PRD A13). Before the reveal: the flag's metadata only (rule,
 * source, sender code, the report's reason). "Reveal message" is the audited click
 * (RevealFlaggedMessage): then the flagged message, at most three messages either side in the same
 * thread, and the masked / matched excerpt — never the rest of the conversation. Decisions
 * (DecideMessageFlag) and freeze / unfreeze (SetConversationFrozen) each re-check their own
 * permission on the server. `revealed` is locked: it can't be set from the browser.
 */
#[Layout('layouts::admin', ['title' => 'Review flagged message'])]
final class FlagReview extends Component
{
    /** Session key prefix: this admin revealed this flag (the image route checks it). */
    public const REVEALED_SESSION = 'admin.chat.revealed.';

    /** How long a reveal lets the flagged image be fetched. */
    public const REVEAL_MINUTES = 30;

    #[Locked]
    public string $flagId = '';

    #[Locked]
    public bool $revealed = false;

    public string $note = '';

    public string $freezeReason = '';

    public function mount(string $flag): void
    {
        Gate::authorize('chat.view_flagged');
        $this->flagId = $this->flag($flag)->id;
    }

    public function reveal(RevealFlaggedMessage $reveal): void
    {
        $reveal->handle($this->admin(), $this->flag());
        $this->revealed = true;
        session()->put(self::REVEALED_SESSION.$this->flagId, now()->getTimestamp());
    }

    public function decide(string $decision, DecideMessageFlag $decide): void
    {
        $choice = MessageFlagDecision::tryFrom($decision) ?? abort(404);

        try {
            $decide->handle($this->admin(), $this->flag(), $choice, $this->note);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        } catch (MessageFlagUnavailable|MemberStateConflict $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->note = '';
        $this->dispatch('close-modal', name: 'suspend-sender');
        $this->dispatch('toast', type: 'success', title: __('Decision saved: :decision.', ['decision' => $choice->label()]));
    }

    public function setFrozen(bool $frozen, SetConversationFrozen $set): void
    {
        $thread = Conversation::query()->find($this->flag()->conversation_id) ?? abort(404);

        try {
            $set->handle($this->admin(), $thread, $frozen, $this->freezeReason);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        }

        $this->freezeReason = '';
        $this->dispatch('close-modal', name: 'freeze-thread');
        $this->dispatch('toast', type: 'success', title: $frozen ? __('Conversation frozen.') : __('Conversation opened again.'));
    }

    /** Lift the automatic freeze after reports for the sender: all their REPORTS-frozen threads open. */
    public function reopenReportFrozen(ReopenReportFrozenThreads $reopen): void
    {
        $sender = $this->flag()->sender_profile_id !== null ? Profile::withTrashed()->find($this->flag()->sender_profile_id) : null;
        if ($sender === null) {
            abort(404);
        }

        try {
            $opened = $reopen->handle($this->admin(), $sender, $this->freezeReason);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        }

        $this->freezeReason = '';
        $this->dispatch('close-modal', name: 'reopen-report-frozen');
        $this->dispatch('toast', type: 'success', title: trans_choice(':count conversation opened again.|:count conversations opened again.', $opened, ['count' => $opened]));
    }

    public function render(FlagContext $context): View
    {
        $flag = $this->flag();
        $flag->load(['sender:id,code,user_id', 'report:id,reason,details,created_at', 'decidedBy:id,name']);
        $admin = $this->admin();
        $thread = Conversation::query()->find($flag->conversation_id, ['id', 'status', 'frozen_reason', 'frozen_at']);
        $open = $flag->status->isOpen() && ($flag->status !== MessageFlagStatus::Escalated || $admin->isSuperAdmin());

        return view('livewire.admin.chat.flag-review', [
            'flag' => $flag,
            'thread' => $thread,
            'context' => $this->revealed ? $context->for($flag) : null,
            'otherOpenFlags' => MessageFlag::query()->where('message_id', $flag->message_id)->whereKeyNot($flag->id)->open()->count(),
            'decisions' => $open ? collect(MessageFlagDecision::cases())
                ->filter(fn (MessageFlagDecision $d): bool => $admin->can($d->permission()))
                ->reject(fn (MessageFlagDecision $d): bool => $d === MessageFlagDecision::Escalate && $flag->status === MessageFlagStatus::Escalated)
                ->values()->all() : [],
            'escalatedLocked' => $flag->status === MessageFlagStatus::Escalated && ! $admin->isSuperAdmin(),
            'canFreeze' => $admin->can('chat.freeze') && $thread !== null && $thread->status !== ConversationStatus::Closed,
            // Threads of the sender the automatic freeze after reports closed (opened again as one unit).
            'reportFrozen' => $flag->sender_profile_id === null || ! $admin->can('chat.freeze') ? 0
                : Conversation::query()->whereIn('id', ConversationParticipant::query()->select('conversation_id')->where('profile_id', $flag->sender_profile_id))
                    ->where('status', ConversationStatus::Frozen->value)->where('frozen_reason', FreezeReason::Reports->value)->count(),
            'senderUrl' => $flag->sender !== null && $admin->can('members.view') ? route('admin.members.show', ['profile' => $flag->sender->code]) : null,
        ]);
    }

    private function flag(?string $id = null): MessageFlag
    {
        $id ??= $this->flagId;

        return preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? (MessageFlag::query()->find(strtolower($id)) ?? abort(404)) : abort(404);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
