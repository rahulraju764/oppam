<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers\Tabs;

use App\Actions\Admin\Brokers\Staff\DeactivateBureauStaff;
use App\Actions\Admin\Brokers\Staff\ResetStaffDevices;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Exceptions\Broker\StaffRefused;
use App\Livewire\Admin\Brokers\Concerns\IsBureauTab;
use App\Models\AdminUser;
use App\Queries\Broker\StaffDirectory;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Component;

/**
 * A07 bureau "Staff" (§11A B.12, `brokers.staff.view`): the bureau's logins with role, scope,
 * status and assigned clients, seats used / limit, open invitations. `brokers.staff.edit` may
 * deactivate a staff login or reset a login's devices (OTP again on the next sign-in) — never add
 * staff (R-M13-24: the Owner invites). Each action is re-authorized in its Action and asks for a
 * reason.
 */
final class StaffTab extends Component
{
    use IsBureauTab;

    public string $target = '';

    public string $reason = '';

    public function mount(string $code): void
    {
        $this->openTab($code, 'brokers.staff.view');
    }

    public function confirm(string $modal, string $staffCode): void
    {
        $this->resetErrorBag();
        $this->reset('reason');
        $this->target = $staffCode;
        $this->dispatch('open-modal', name: $modal);
    }

    public function deactivate(DeactivateBureauStaff $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), $this->target, $this->reason), 'staff-deactivate',
            __(':code deactivated and signed out. Their clients are unassigned.', ['code' => $this->target]));
    }

    public function resetDevices(ResetStaffDevices $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->broker(), $this->target, $this->reason), 'staff-reset',
            __(':code was signed out; the next sign-in asks for an OTP.', ['code' => $this->target]));
    }

    public function render(StaffDirectory $directory): View
    {
        $this->authorize('brokers.staff.view');
        $broker = $this->broker();

        return view('livewire.admin.brokers.tabs.staff-tab', [
            'seats' => $directory->seats($broker),
            'staff' => $directory->staff($broker),
            'invitations' => $directory->openInvitations($broker),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function run(Closure $action, string $modal, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (BrokerStateConflict|StaffRefused $refused) {
            $this->dispatch('toast', type: 'error', message: $refused->getMessage());

            return;
        } catch (ModelNotFoundException) {
            $this->dispatch('toast', type: 'error', message: __('No staff login with this code in this bureau.'));

            return;
        }

        $this->reset('reason', 'target');
        $this->dispatch('close-modal', name: $modal);
        $this->dispatch('toast', type: 'success', message: $done);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
