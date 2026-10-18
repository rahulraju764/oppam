<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Chat;

use App\Enums\MessageFlagRule;
use App\Enums\MessageFlagSource;
use App\Enums\MessageFlagStatus;
use App\Models\MessageFlag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A13 flagged messages queue (PRD A13): automatic flags and member reports, oldest first. The list
 * is metadata only — rule, source, sender code, when — never message text; the text is revealed
 * on the review page, audited. Filters: waiting / escalated / decided, rule, source (allow-listed
 * enums). Needs `chat.view_flagged`. Live: a new flag (`admin.queues` `.queue.count`) re-reads it.
 *
 * @property-read LengthAwarePaginator<int, MessageFlag> $flags
 */
#[Layout('layouts::admin', ['title' => 'Flagged messages'])]
final class FlaggedQueue extends Component
{
    use WithPagination;

    public const VIEWS = ['waiting', 'escalated', 'decided'];

    #[Url(as: 'view')]
    public string $view = 'waiting';

    #[Url]
    public string $rule = '';

    #[Url]
    public string $source = '';

    public function mount(): void
    {
        Gate::authorize('chat.view_flagged');
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:admin.queues,.queue.count' => '$refresh'];
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, MessageFlag> */
    #[Computed]
    public function flags(): LengthAwarePaginator
    {
        $rule = MessageFlagRule::tryFrom($this->rule);
        $source = MessageFlagSource::tryFrom($this->source);

        return MessageFlag::query()
            ->with(['sender:id,code', 'decidedBy:id,name'])
            ->when($this->view === 'escalated', fn ($q) => $q->where('status', MessageFlagStatus::Escalated->value))
            ->when($this->view === 'decided', fn ($q) => $q->whereIn('status', [MessageFlagStatus::Dismissed->value, MessageFlagStatus::Actioned->value]))
            ->when(! in_array($this->view, ['escalated', 'decided'], true), fn ($q) => $q->open())
            ->when($rule !== null, fn ($q) => $q->where('rule', $rule?->value))
            ->when($source !== null, fn ($q) => $q->where('source', $source?->value))
            ->when($this->view === 'decided', fn ($q) => $q->orderByDesc('decided_at'), fn ($q) => $q->orderBy('created_at'))
            ->paginate(25, ['id', 'message_id', 'sender_profile_id', 'source', 'rule', 'status', 'decision', 'decided_by_admin_id', 'decided_at', 'created_at']);
    }

    public function render(): View
    {
        return view('livewire.admin.chat.flagged-queue', [
            'rules' => MessageFlagRule::options(),
            'sources' => collect(MessageFlagSource::cases())->mapWithKeys(fn (MessageFlagSource $s): array => [$s->value => $s->label()])->all(),
            'views' => ['waiting' => __('Waiting'), 'escalated' => __('Escalated'), 'decided' => __('Decided')],
        ]);
    }
}
