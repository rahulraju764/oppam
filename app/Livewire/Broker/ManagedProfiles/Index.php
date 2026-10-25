<?php

declare(strict_types=1);

namespace App\Livewire\Broker\ManagedProfiles;

use App\Actions\Broker\ManagedProfiles\AssignManagedProfiles;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Models\User;
use App\Queries\Broker\ManagedProfileList;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/profiles (§11A B.6 "Manage", B.11 `profiles.view`): the bureau's managed clients with a
 * status filter (and "deleted" for the 30-day recovery), a name / code search and an assigned-staff
 * filter (all `#[Url]`), and — with `profiles.assign` — bulk assignment. Always the caller's
 * ManagedProfileScope (assigned-only staff see their own clients). Rows refresh live on
 * `profile.moderated` (`broker.{broker}`).
 */
final class Index extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'staff')]
    public string $assignee = '';

    /** @var list<string> profile codes ticked for bulk assignment */
    public array $selected = [];

    /** Bulk assignment target: a staff id of this bureau, or '' to clear. */
    public string $assignTo = '';

    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::ProfilesView);
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $staff = app(BureauGate::class)->staff($this->user());

        return $staff !== null ? ['echo-private:broker.'.$staff->broker_id.',.profile.moderated' => '$refresh'] : [];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'assignee'], true)) {
            $this->selected = [];
            $this->resetPage();
        }
    }

    public function assignSelected(AssignManagedProfiles $assign): void
    {
        $codes = array_values(array_filter($this->selected, 'is_string'));

        try {
            $changed = $assign->handle($this->user(), $codes, $this->assignTo === '' ? null : $this->assignTo);
        } catch (ManagedProfileRefused $e) {
            $this->addError('assignTo', $e->getMessage());

            return;
        }

        $this->selected = [];
        $this->dispatch('toast', type: 'success', message: trans_choice('{0} Nothing changed.|{1} 1 profile updated.|[2,*] :count profiles updated.', $changed, ['count' => $changed]));
    }

    public function render(BureauGate $gate, ManagedProfileList $list): View
    {
        $user = $this->user();
        $staff = $gate->authorize($user, BureauPermission::ProfilesView);
        $canAssign = $gate->allows($user, BureauPermission::ProfilesAssign);
        $assignees = $list->assignees($staff);

        return view('livewire.broker.managed-profiles.index', [
            'profiles' => $list->page($staff, $this->status, $this->search, $this->assignee),
            'statuses' => [...ProfileStatus::options(), ManagedProfileList::DELETED => __('Deleted (can be restored)')],
            'assignees' => $assignees->mapWithKeys(fn ($s): array => [$s->id => $s->displayName()])->all(),
            'canAssign' => $canAssign,
            'canCreate' => $gate->allows($user, BureauPermission::ProfilesCreate),
            'showStaffFilter' => $staff->profile_scope === ProfileScope::All,
            'filtered' => $this->status !== '' || trim($this->search) !== '' || $this->assignee !== '',
        ])->layout('layouts::broker', ['seo' => SeoData::private('Client profiles | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
