<?php

declare(strict_types=1);

namespace App\Livewire\Member\Support;

use App\Actions\Support\ReplyToOwnTicket;
use App\Data\Content\SeoData;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /help/{ticket} — one of the member's own support requests: the conversation with our team
 * (internal notes never shown) and a reply box while the request isn't closed. Another member's
 * code is a 404. A reply from support arrives as a notification and the thread re-reads live.
 */
final class TicketThread extends Component
{
    #[Locked]
    public string $code = '';

    public string $reply = '';

    public function mount(string $ticket): void
    {
        $this->code = $this->ticket($ticket)->code;
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:App.Models.User.'.$this->member()->id.',.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated' => '$refresh'];
    }

    public function send(ReplyToOwnTicket $reply): void
    {
        try {
            $reply->handle($this->member(), $this->code, $this->reply);
        } catch (SafetyRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('reply');
        $this->dispatch('toast', type: 'success', title: __('Reply sent.'));
    }

    public function render(): View
    {
        $ticket = $this->ticket();

        return view('livewire.member.support.ticket-thread', [
            'ticket' => $ticket,
            'messages' => $ticket->messages()->visibleToMember()->get(['id', 'author', 'body', 'created_at']),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::member', ['seo' => SeoData::private($ticket->code.' | Oppam Matrimony')]);
    }

    private function ticket(?string $code = null): SupportTicket
    {
        $code ??= $this->code;

        return preg_match('/^TKT[0-9]+$/', $code) === 1
            ? (SupportTicket::query()->ownedBy($this->member())->where('code', $code)->first() ?? abort(404))
            : abort(404);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
