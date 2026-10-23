<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Support;

use App\Actions\Admin\Support\RespondToTicket;
use App\Actions\Admin\Support\UpdateTicket;
use App\Domain\Support\CannedResponseRenderer;
use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\AdminUser;
use App\Models\Block;
use App\Models\SupportCannedResponse;
use App\Models\SupportTicket;
use App\Queries\Billing\CurrentMembership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One support ticket (PRD A10 "threaded view with internal notes; canned responses with
 * variables; member context sidebar; block investigation (read-only)"). Everyone with
 * `support.view` reads it; replies, notes, status / topic changes and canned responses need
 * `support.respond` (each Action re-checks). Internal notes are marked and never reach the member.
 * The sidebar shows the member's account, plan, the in-app context and how many blocks they made /
 * received — counts only. Live: a member's reply re-reads the thread (`admin.queues`).
 */
#[Layout('layouts::admin', ['title' => 'Support ticket'])]
final class TicketShow extends Component
{
    #[Locked]
    public string $code = '';

    public string $reply = '';

    public bool $internal = false;

    public string $canned = '';

    public function mount(string $ticket): void
    {
        Gate::authorize('support.view');
        $this->code = $this->ticket($ticket)->code;
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:admin.queues,.queue.count' => '$refresh'];
    }

    /** Put a canned response (variables filled for this ticket) into the reply box, to edit before sending. */
    public function useCanned(CannedResponseRenderer $renderer): void
    {
        Gate::authorize('support.respond');
        $response = SupportCannedResponse::query()->find($this->canned);
        if ($response !== null) {
            $this->reply = $renderer->render($response->body, $this->ticket(), $this->admin());
        }
        $this->canned = '';
    }

    public function send(RespondToTicket $respond): void
    {
        try {
            $respond->handle($this->admin(), $this->ticket(), $this->reply, $this->internal);
        } catch (AuthorizationException) {
            $this->denied();

            return;
        } catch (SafetyRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: $this->internal ? __('Internal note added.') : __('Reply sent.'));
        $this->reset('reply', 'internal');
    }

    public function setStatus(string $status, UpdateTicket $update): void
    {
        $this->attempt(fn () => $update->status($this->admin(), $this->ticket(), TicketStatus::tryFrom($status) ?? abort(404)));
    }

    public function setCategory(string $category, UpdateTicket $update): void
    {
        $this->attempt(fn () => $update->category($this->admin(), $this->ticket(), TicketCategory::tryFrom($category) ?? abort(404)));
    }

    public function assignToMe(UpdateTicket $update): void
    {
        $this->attempt(fn () => $update->assignToMe($this->admin(), $this->ticket()));
    }

    public function render(CurrentMembership $membership): View
    {
        $ticket = $this->ticket();
        $ticket->load(['messages.admin:id,name', 'assignee:id,name', 'user:id,status,email,phone,created_at']);
        $profile = $ticket->user?->profile;
        $admin = $this->admin();

        return view('livewire.admin.support.ticket-show', [
            'ticket' => $ticket,
            'profile' => $profile,
            'plan' => $profile !== null ? ($membership->current($profile)?->plan->name ?? __('Free')) : null,
            'blocksMade' => $profile !== null ? Block::query()->where('blocker_profile_id', $profile->id)->count() : 0,
            'blocksReceived' => $profile !== null ? Block::query()->where('blocked_profile_id', $profile->id)->count() : 0,
            'otherTickets' => $ticket->user_id !== null
                ? SupportTicket::query()->where('user_id', $ticket->user_id)->whereKeyNot($ticket->id)->latest()->limit(5)->get(['id', 'code', 'subject', 'status'])
                : collect(),
            'cannedOptions' => $admin->can('support.respond')
                ? SupportCannedResponse::query()->where(fn ($q) => $q->whereNull('category')->orWhere('category', $ticket->category->value))
                    ->orderBy('title')->pluck('title', 'id')->all()
                : [],
            'activity' => $this->activity($ticket),
            'canRespond' => $admin->can('support.respond'),
            'statuses' => TicketStatus::options(),
            'categories' => TicketCategory::options(),
            'memberUrl' => $profile !== null && $admin->can('members.view') ? route('admin.members.show', ['profile' => $profile->code]) : null,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    /**
     * In-app help context (OpenTicket): the member's last actions, IST.
     *
     * @return list<array{action: string, at: string}>
     */
    private function activity(SupportTicket $ticket): array
    {
        $zone = (string) config('oppam.display_timezone');
        $actions = is_array($ticket->context['actions'] ?? null) ? $ticket->context['actions'] : [];

        return array_values(array_map(fn (mixed $row): array => [
            'action' => is_array($row) ? (string) ($row['action'] ?? '') : '',
            'at' => is_array($row) && is_string($row['at'] ?? null) ? CarbonImmutable::parse($row['at'])->setTimezone($zone)->format('d M, g:i a') : '',
        ], array_slice($actions, 0, 20)));
    }

    private function attempt(callable $change): void
    {
        try {
            $change();
        } catch (AuthorizationException) {
            $this->denied();

            return;
        }

        $this->dispatch('toast', type: 'success', title: __('Ticket updated.'));
    }

    private function ticket(?string $code = null): SupportTicket
    {
        $code ??= $this->code;

        return preg_match('/^TKT[0-9]+$/', $code) === 1 ? (SupportTicket::query()->where('code', $code)->first() ?? abort(404)) : abort(404);
    }

    private function denied(): void
    {
        $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
