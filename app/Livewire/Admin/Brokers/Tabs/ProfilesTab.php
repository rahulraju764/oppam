<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers\Tabs;

use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Livewire\Admin\Brokers\Concerns\IsBureauTab;
use App\Models\Profile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 bureau "Managed profiles" (§11A B.12): the profiles the bureau manages now (unclaimed), by
 * status, with the assigned staff code; a PENDING_REVIEW row links into its A04 review (R-M13-14 —
 * no shortcut), the others into A03. `brokers.view`.
 */
final class ProfilesTab extends Component
{
    use IsBureauTab, WithPagination;

    public string $status = '';

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $broker = $this->broker();
        $status = ProfileStatus::tryFrom($this->status);

        return view('livewire.admin.brokers.tabs.profiles-tab', [
            'profiles' => Profile::query()
                ->where('managed_by_broker_id', $broker->id)
                ->where('owner_type', ProfileOwnerType::BrokerManaged->value)
                ->when($status !== null, fn (Builder $q) => $q->where('status', $status?->value))
                ->with('assignedStaff:id,code')
                ->select(['id', 'code', 'first_name', 'last_name', 'status', 'assigned_staff_id', 'import_batch_id', 'created_at', 'updated_at'])
                ->latest('updated_at')->orderByDesc('id')
                ->paginate(25),
            'statuses' => collect(ProfileStatus::cases())->mapWithKeys(fn (ProfileStatus $s): array => [$s->value => $s->label()])->all(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
