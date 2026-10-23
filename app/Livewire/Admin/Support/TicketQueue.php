<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Support;

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A10 support tickets (/support/tickets): by status (waiting for us first, the nearest
 * first-reply deadline first; overdue marked), optional category and an exact ticket code or
 * email search. Live: new tickets and replies re-read the list (`admin.queues`).
 *
 * @property-read LengthAwarePaginator<int, SupportTicket> $tickets
 */
#[Layout('layouts::admin', ['title' => 'Support tickets'])]
final class TicketQueue extends Component
{
    use WithPagination;

    #[Url(as: 'status', except: 'OPEN')]
    public string $status = 'OPEN';

    #[Url(as: 'category', except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('support.view');
        $this->normalise();
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:admin.queues,.queue.count' => '$refresh'];
    }

    public function updated(): void
    {
        $this->normalise();
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, SupportTicket> */
    #[Computed]
    public function tickets(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return SupportTicket::query()->with('assignee:id,name')
            ->when($this->status !== 'ALL', fn ($q) => $q->where('status', $this->status))
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when($term !== '', fn ($q) => preg_match('/^TKT[0-9]+$/i', $term) === 1
                ? $q->where('code', strtoupper($term))
                : $q->where('email', mb_strtolower($term)))
            ->orderByRaw('first_responded_at IS NOT NULL')->orderBy('first_response_due_at')->orderBy('id')
            ->paginate(25, ['id', 'code', 'source', 'category', 'status', 'name', 'subject', 'assigned_admin_id', 'first_response_due_at', 'first_responded_at', 'last_activity_at']);
    }

    public function render(): View
    {
        $counts = SupportTicket::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        return view('livewire.admin.support.ticket-queue', [
            'statuses' => ['ALL' => __('All')] + collect(TicketStatus::options())
                ->map(fn (string $label, string $key): string => $label.' ('.($counts[$key] ?? 0).')')->all(),
            'categories' => TicketCategory::options(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function normalise(): void
    {
        if ($this->status !== 'ALL' && TicketStatus::tryFrom($this->status) === null) {
            $this->status = TicketStatus::Open->value;
        }
        if (TicketCategory::tryFrom($this->category) === null) {
            $this->category = '';
        }
        $this->search = mb_substr($this->search, 0, 120);
    }
}
