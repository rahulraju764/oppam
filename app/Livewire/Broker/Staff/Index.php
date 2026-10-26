<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Staff;

use App\Actions\Broker\Staff\CancelInvitation;
use App\Actions\Broker\Staff\InviteStaff;
use App\Data\Broker\StaffInviteData;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ProfileScope;
use App\Exceptions\Broker\StaffRefused;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\User;
use App\Queries\Broker\StaffDirectory;
use App\Support\Facades\Feature;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /broker/staff (§11A B.8 / B.11, `staff.manage` — the Owner): seats used / limit, invite a staff
 * member (name, mobile, role, profile scope), the bureau's logins and the open invitations (cancel).
 * Every action is an Action that checks `staff.manage` and the bureau again. With the
 * `broker.staff` flag off the page explains that staff accounts are coming.
 */
final class Index extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $role = 'TELECALLER';

    public string $scope = 'ASSIGNED';

    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::StaffManage);
    }

    /** B.2: Telecallers see assigned clients only — the form follows the role (the Action enforces it). */
    public function updatedRole(): void
    {
        if ($this->role === BureauRole::Telecaller->value) {
            $this->scope = ProfileScope::Assigned->value;
        }
    }

    public function invite(InviteStaff $invite): void
    {
        $this->resetErrorBag();

        try {
            $invitation = $invite->handle($this->user(), new StaffInviteData($this->name, $this->phone, $this->role, $this->scope));
        } catch (StaffRefused $refused) {
            $this->addError('phone', $refused->getMessage());

            return;
        }

        $this->reset('name', 'phone');
        $this->dispatch('toast', type: 'success', message: __('Invitation sent to :phone. The link works for 72 hours.', [
            'phone' => PhoneNumber::fromE164($invitation->phone)->masked(),
        ]));
    }

    public function cancel(string $invitationId, CancelInvitation $cancel): void
    {
        $cancel->handle($this->user(), $invitationId);
        $this->dispatch('toast', type: 'success', message: __('Invitation cancelled. Its seat is free again.'));
    }

    public function render(BureauGate $gate, StaffDirectory $directory): View
    {
        $staff = $gate->authorize($this->user(), BureauPermission::StaffManage);
        /** @var Broker $broker */
        $broker = $staff->broker;

        return view('livewire.broker.staff.index', [
            'enabled' => Feature::active(Flag::BrokerStaff),
            'verified' => $broker->isVerifiedAndActive(),
            'seats' => $directory->seats($broker),
            'members' => $directory->staff($broker),
            'invitations' => $directory->openInvitations($broker),
            'roles' => array_filter(BureauRole::options(), fn (string $key): bool => $key !== BureauRole::Owner->value, ARRAY_FILTER_USE_KEY),
            'scopes' => BrokerStaff::scopeOptionsFor(BureauRole::tryFrom($this->role) ?? BureauRole::Manager),
        ])->layout('layouts::broker', ['seo' => SeoData::private('Staff | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
