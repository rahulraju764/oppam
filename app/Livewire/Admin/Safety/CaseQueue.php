<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Safety;

use App\Enums\AbuseCaseStatus;
use App\Enums\CasePriority;
use App\Models\AbuseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A10 abuse cases (/safety/cases): OPEN cases most urgent first (P0 → P2), then by SLA due time —
 * overdue ones marked; or the decided ones (90 days). Metadata only: no report text, no messages
 * (the review page shows those, and message evidence only after an audited reveal). Live: a new
 * report or a decision re-reads the list (`admin.queues`).
 *
 * @property-read LengthAwarePaginator<int, AbuseCase> $cases
 */
#[Layout('layouts::admin', ['title' => 'Safety cases'])]
final class CaseQueue extends Component
{
    use WithPagination;

    public const LISTS = ['open', 'mine', 'decided'];

    #[Url(as: 'list', except: 'open')]
    public string $list = 'open';

    #[Url(as: 'priority', except: '')]
    public string $priority = '';

    public function mount(): void
    {
        Gate::authorize('safety.reports.view');
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

    public function show(string $list): void
    {
        $this->list = $list;
        $this->normalise();
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, AbuseCase> */
    #[Computed]
    public function cases(): LengthAwarePaginator
    {
        $query = AbuseCase::query()->with(['reported:id,code,first_name,last_name', 'assignee:id,name', 'decidedBy:id,name']);

        if ($this->list === 'decided') {
            $query->where('status', AbuseCaseStatus::Closed->value)->where('decided_at', '>=', now()->subDays(90))->orderByDesc('decided_at');
        } else {
            $query->open()
                ->when($this->list === 'mine', fn ($q) => $q->where('assigned_admin_id', auth('admin')->id()))
                ->orderByRaw("FIELD(priority, 'P0', 'P1', 'P2')")->orderBy('sla_due_at');
        }

        return $query->when($this->priority !== '', fn ($q) => $q->where('priority', $this->priority))->orderBy('id')->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.admin.safety.case-queue', [
            'counts' => AbuseCase::query()->open()->selectRaw('priority, count(*) as n')->groupBy('priority')->pluck('n', 'priority')->all(),
            'priorities' => CasePriority::options(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function normalise(): void
    {
        if (! in_array($this->list, self::LISTS, true)) {
            $this->list = 'open';
        }
        if (CasePriority::tryFrom($this->priority) === null) {
            $this->priority = '';
        }
    }
}
