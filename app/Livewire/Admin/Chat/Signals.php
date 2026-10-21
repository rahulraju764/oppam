<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Chat;

use App\Actions\Admin\Chat\DecideEngagementSignal;
use App\Actions\Admin\Chat\LiftRestriction;
use App\Enums\EngagementSignalDecision;
use App\Enums\EngagementSignalStatus;
use App\Enums\EngagementSignalType;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Engagement\SignalUnavailable;
use App\Models\AdminUser;
use App\Models\EngagementSignal;
use App\Models\MemberRestriction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A13 abuse signals (PRD A13 "Engagement abuse monitors"): like spam, mass favourites, interest spam,
 * repeated messages, one-sided messaging, new accounts messaging many members — each with its counts
 * (never message text) and one-click actions: pause sending (1 h / 24 h / 7 d), warn, suspend, or
 * dismiss (DecideEngagementSignal re-checks each permission). "Paused members" lists running pauses,
 * each can be lifted (LiftRestriction). Needs `chat.view_flagged`; live: a new signal
 * (`admin.queues` `.queue.count`) re-reads the list.
 *
 * @property-read LengthAwarePaginator<int, EngagementSignal> $signals
 * @property-read LengthAwarePaginator<int, MemberRestriction> $pauses
 */
#[Layout('layouts::admin', ['title' => 'Abuse signals'])]
final class Signals extends Component
{
    use WithPagination;

    #[Url(as: 'view')]
    public string $view = 'waiting';

    #[Url]
    public string $type = '';

    /** The row a dialog acts on — chosen in the browser, so re-read and re-authorized on every action. */
    public string $selected = '';

    public int $hours = 24;

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('chat.view_flagged');
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:admin.queues,.queue.count' => '$refresh'];
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['view', 'type'], true)) {
            $this->resetPage();
        }
    }

    /** Open a decision's dialog for one signal. */
    public function choose(string $signalId, string $dialog): void
    {
        $this->selected = $signalId;
        $this->reset('note');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: $dialog);
    }

    public function decide(string $decision, DecideEngagementSignal $decide): void
    {
        $choice = EngagementSignalDecision::tryFrom($decision) ?? abort(404);
        $signal = $this->signal($this->selected);

        try {
            $decide->handle($this->admin(), $signal, $choice, $choice === EngagementSignalDecision::RateLimit ? $this->hours : null, $this->note);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        } catch (SignalUnavailable|MemberStateConflict $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('note', 'selected');
        $this->dispatch('close-modal', name: 'signal-'.strtolower($choice->value));
        $this->dispatch('toast', type: 'success', title: __('Decision saved: :decision.', ['decision' => $choice->label()]));
    }

    public function lift(LiftRestriction $lift): void
    {
        $restriction = preg_match('/^[0-9a-z]{26}$/i', $this->selected) === 1
            ? (MemberRestriction::query()->find(strtolower($this->selected)) ?? abort(404)) : abort(404);

        try {
            $lift->handle($this->admin(), $restriction, $this->note);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        } catch (SignalUnavailable $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('note', 'selected');
        $this->dispatch('close-modal', name: 'lift-pause');
        $this->dispatch('toast', type: 'success', title: __('The pause has been lifted.'));
    }

    /** @return LengthAwarePaginator<int, EngagementSignal> */
    #[Computed]
    public function signals(): LengthAwarePaginator
    {
        $type = EngagementSignalType::tryFrom($this->type);

        return EngagementSignal::query()
            ->with(['profile:id,code', 'decidedBy:id,name'])
            ->when($this->view === 'decided',
                fn ($q) => $q->where('status', '!=', EngagementSignalStatus::Open->value)->orderByDesc('decided_at'),
                fn ($q) => $q->open()->orderBy('created_at'))
            ->when($type !== null, fn ($q) => $q->where('type', $type?->value))
            ->paginate(25, ['id', 'profile_id', 'type', 'details', 'status', 'occurrences', 'last_raised_at', 'decision', 'decided_by_admin_id', 'decided_at', 'created_at']);
    }

    /** @return LengthAwarePaginator<int, MemberRestriction> */
    #[Computed]
    public function pauses(): LengthAwarePaginator
    {
        return MemberRestriction::query()->active()->with(['profile:id,code', 'createdBy:id,name'])
            ->orderBy('until')->paginate(25, ['id', 'profile_id', 'until', 'reason', 'created_by_admin_id', 'created_at']);
    }

    public function render(): View
    {
        $admin = $this->admin();

        return view('livewire.admin.chat.signals', [
            'types' => EngagementSignalType::options(),
            'views' => ['waiting' => __('Waiting'), 'decided' => __('Decided'), 'paused' => __('Paused members')],
            'decisions' => collect(EngagementSignalDecision::cases())
                ->filter(fn (EngagementSignalDecision $d): bool => $admin->can($d->permission()))->values()->all(),
            'canLift' => $admin->can('chat.moderate'),
            'canViewMembers' => $admin->can('members.view'),
            'hourOptions' => collect(EngagementSignalDecision::RATE_LIMIT_HOURS)->mapWithKeys(fn (int $h): array => [$h => match ($h) {
                1 => __('1 hour'), 24 => __('24 hours'), default => __(':days days', ['days' => intdiv($h, 24)]),
            }])->all(),
        ]);
    }

    private function signal(string $id): EngagementSignal
    {
        return preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? (EngagementSignal::query()->find(strtolower($id)) ?? abort(404)) : abort(404);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
