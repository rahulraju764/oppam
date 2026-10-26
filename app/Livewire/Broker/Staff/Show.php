<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Staff;

use App\Actions\Broker\Staff\DeactivateStaff;
use App\Actions\Broker\Staff\UpdateStaffPermissions;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\BureauStaffStatus;
use App\Exceptions\Broker\StaffRefused;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\User;
use App\Queries\Broker\BureauActivity;
use App\Queries\Broker\StaffDirectory;
use App\Queries\Broker\StaffPerformance;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/staff/{code} (§11A B.8 / B.11, `staff.manage` — the Owner): one staff member's
 * switch-offs (only within their role's ceiling), profile scope, assigned clients, recent activity
 * and monthly performance, and "Deactivate" with a typed confirmation (their staff code). The code
 * is locked and looked up inside the caller's bureau (else 404); every change is an Action that
 * checks `staff.manage` again. The Owner's own row is shown read-only.
 */
final class Show extends Component
{
    use WithPagination;

    #[Locked]
    public string $code = '';

    /** @var list<string> */
    public array $disabled = [];

    public string $scope = 'ALL';

    #[Url(as: 'month')]
    public string $month = '';

    public string $confirm = '';

    public function mount(string $code): void
    {
        $this->code = $code;
        $staff = $this->staff();
        $this->disabled = $staff->disabled_permissions ?? [];
        $this->scope = $staff->effectiveScope()->value;   // what is enforced (a Telecaller: assigned only)
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? $this->month : StaffPerformance::currentMonth();
    }

    public function updatedMonth(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? $this->month : StaffPerformance::currentMonth();
    }

    public function save(UpdateStaffPermissions $update): void
    {
        try {
            $update->handle($this->user(), $this->code, $this->disabled, $this->scope);
        } catch (StaffRefused $refused) {
            $this->dispatch('toast', type: 'error', message: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Saved. The change applies from their next click.'));
    }

    public function deactivate(DeactivateStaff $deactivate): mixed
    {
        if (trim($this->confirm) !== $this->code) {
            $this->addError('confirm', __('Type :code to confirm.', ['code' => $this->code]));

            return null;
        }

        try {
            $unassigned = $deactivate->handle($this->user(), $this->code);
        } catch (StaffRefused $refused) {
            $this->addError('confirm', $refused->getMessage());

            return null;
        }

        session()->flash('status', trans_choice(
            '{0} :code was deactivated and signed out.|{1} :code was deactivated and signed out; 1 client went back to your unassigned list.|[2,*] :code was deactivated and signed out; :count clients went back to your unassigned list.',
            $unassigned, ['code' => $this->code, 'count' => $unassigned],
        ));

        return $this->redirectRoute('broker.staff.index', navigate: true);
    }

    public function render(StaffDirectory $directory, BureauActivity $activity, StaffPerformance $performance): View
    {
        $staff = $this->staff();
        /** @var Broker $broker */
        $broker = $staff->broker;
        $viewer = app(BureauGate::class)->staff($this->user());

        return view('livewire.broker.staff.show', [
            'staff' => $staff,
            'editable' => ! $staff->isOwner() && $staff->status !== BureauStaffStatus::Deactivated,
            'switchable' => UpdateStaffPermissions::switchable($staff),
            'scopes' => BrokerStaff::scopeOptionsFor($staff->role),
            'assigned' => $directory->assignedProfiles($staff),
            'recent' => $viewer !== null ? $activity->query($viewer, ['staff' => $staff->code])->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['id', 'action', 'subject_label', 'created_at']) : collect(),
            'figures' => $performance->forMonth($broker, $this->month)[$staff->user_id] ?? null,
            'monthLabel' => StaffPerformance::range($this->month)[0]->timezone((string) config('oppam.display_timezone'))->format('F Y'),
        ])->layout('layouts::broker', ['seo' => SeoData::private($staff->code.' | Oppam Matrimony')]);
    }

    private function staff(): BrokerStaff
    {
        $caller = app(BureauGate::class)->authorize($this->user(), BureauPermission::StaffManage);
        /** @var Broker $broker */
        $broker = $caller->broker;

        return app(StaffDirectory::class)->find($broker, $this->code);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
