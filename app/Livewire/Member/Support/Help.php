<?php

declare(strict_types=1);

namespace App\Livewire\Member\Support;

use App\Actions\Support\OpenTicket;
use App\Data\Content\SeoData;
use App\Data\Support\TicketRequestData;
use App\Enums\TicketCategory;
use App\Enums\TicketSource;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /help — in-app help (PRD A10 "in-app help (auto-attaches plan & last 20 actions)"): ask the
 * support team (OpenTicket from IN_APP adds the context) and the member's own requests, newest
 * activity first, 10 per page. Only the member's own tickets are ever listed.
 */
final class Help extends Component
{
    use WithPagination;

    public string $category = '';

    public string $subject = '';

    public string $message = '';

    public function send(OpenTicket $open): void
    {
        try {
            $ticket = $open->handle($this->member(), new TicketRequestData('', null, null, $this->category, $this->subject, $this->message),
                TicketSource::InApp, (string) request()->ip());
        } catch (SafetyRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('category', 'subject', 'message');
        $this->resetPage();
        $this->dispatch('toast', type: 'success', title: __('Request :code sent. We’ll reply here and by email.', ['code' => $ticket->code]));
    }

    public function render(): View
    {
        /** @var LengthAwarePaginator<int, SupportTicket> $tickets */
        $tickets = SupportTicket::query()->ownedBy($this->member())
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(10, ['id', 'code', 'subject', 'category', 'status', 'last_activity_at', 'created_at']);

        return view('livewire.member.support.help', [
            'tickets' => $tickets,
            'categories' => TicketCategory::options(),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::member', ['seo' => SeoData::private(__('Help & support').' | Oppam Matrimony')]);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
