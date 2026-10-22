<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Verification;

use App\Domain\Verification\QueueAging;
use App\Enums\SettingKey;
use App\Enums\VerificationStatus;
use App\Models\VerificationRequest;
use App\Support\Facades\Settings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A05 verification queue (/verification): waiting requests — paid members first, then oldest —
 * with aging colours (48 h / 72 h, A15), the automatic fraud flags and who is reviewing each one
 * right now; plus the escalated list and recent decisions. Opening a request claims it (Review).
 * Read-only: no document is ever shown here.
 *
 * @property-read LengthAwarePaginator<int, VerificationRequest> $requests
 */
#[Layout('layouts::admin', ['title' => 'ID verification'])]
final class Queue extends Component
{
    use WithPagination;

    public const LISTS = ['waiting', 'escalated', 'decided'];

    #[Url(as: 'list', except: 'waiting')]
    public string $list = 'waiting';

    public function mount(): void
    {
        Gate::authorize('verification.queue.view');

        if (! in_array($this->list, self::LISTS, true)) {
            $this->list = 'waiting';
        }
    }

    /**
     * Live (§11.0): a new or decided request and a claim taken / released by another officer —
     * the list is read again through its own query.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:admin.queues,.queue.count' => '$refresh',
            'echo-private:admin.queues,.queue.claim' => '$refresh',
        ];
    }

    public function show(string $list): void
    {
        $this->list = in_array($list, self::LISTS, true) ? $list : 'waiting';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, VerificationRequest> */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        $query = VerificationRequest::query()
            ->with(['profile:id,code,first_name,last_name,gender,dob,is_premium', 'claimedBy:id,name', 'decidedBy:id,name']);

        $query = match ($this->list) {
            'escalated' => $query->waiting()->where('status', VerificationStatus::Escalated)->orderBy('submitted_at'),
            'decided' => $query->whereNotIn('status', VerificationStatus::open())->where('decided_at', '>=', now()->subDays(90))->orderByDesc('decided_at'),
            default => $query->waiting()->where('status', VerificationStatus::Pending)->orderByDesc('is_priority')->orderBy('submitted_at'),
        };

        return $query->orderBy('id')->paginate(25);
    }

    public function render(QueueAging $aging): View
    {
        return view('livewire.admin.verification.queue', [
            'aging' => $aging,
            'counts' => VerificationRequest::query()->waiting()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'timezone' => (string) config('oppam.display_timezone'),
            'warnHours' => Settings::int(SettingKey::VerificationAgingWarnHours),
            'breachHours' => Settings::int(SettingKey::VerificationAgingBreachHours),
        ]);
    }
}
