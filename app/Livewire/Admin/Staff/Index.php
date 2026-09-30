<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Staff;

use App\Actions\Admin\Staff\ChangeAdminRole;
use App\Actions\Admin\Staff\InviteAdmin;
use App\Actions\Admin\Staff\SetAdminStatus;
use App\Exceptions\Admin\GuardrailViolation;
use App\Models\AdminInvitation;
use App\Models\AdminUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

/**
 * Admin users (A01): list, invite (72 h link), change role, suspend / reactivate with a typed
 * reason. Page needs staff.view; every action is authorized again inside its Action, and the
 * guardrails (can't grant what you don't hold, last super admin) live there too.
 */
#[Layout('layouts::admin', ['title' => 'Admin users'])]
final class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteRole = '';

    /** The admin the open suspend/reactivate dialog is about (set server-side, never from input). */
    #[Locked]
    public ?string $targetId = null;

    public string $reason = '';

    /** @var array<string, string> admin id => chosen role, for the inline role selects */
    public array $roleChoice = [];

    public function mount(): void
    {
        $this->authorize('staff.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, AdminUser> */
    #[Computed]
    public function admins(): LengthAwarePaginator
    {
        return AdminUser::query()
            ->with('roles')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->paginate(20);
    }

    /** @return Collection<int, AdminInvitation> */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return AdminInvitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->limit(20)->get();
    }

    /** @return Collection<int, Role> */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->where('guard_name', 'admin')->orderBy('name')->get();
    }

    public function invite(InviteAdmin $invite): void
    {
        $this->authorize('staff.invite');
        $this->validate([
            'inviteName' => 'required|string|max:100',
            'inviteEmail' => 'required|email|max:255',
            'inviteRole' => 'required|string|exists:roles,name',
        ]);

        try {
            $invite->handle($this->actor(), $this->inviteName, $this->inviteEmail, $this->inviteRole);
        } catch (GuardrailViolation $violation) {
            $this->addError('inviteRole', $violation->getMessage());

            return;
        }

        $this->reset('inviteName', 'inviteEmail', 'inviteRole');
        $this->dispatch('close-modal', name: 'invite-admin');
        $this->dispatch('toast', type: 'success', message: __('Invitation sent. The link is valid for 72 hours.'));
        unset($this->pendingInvitations);
    }

    public function changeRole(string $adminId, ChangeAdminRole $change): void
    {
        $this->authorize('staff.edit');
        $target = AdminUser::query()->findOrFail($adminId);
        $role = $this->roleChoice[$adminId] ?? '';

        if ($role === '' || ! $this->roles()->contains('name', $role)) {
            $this->dispatch('toast', type: 'error', message: __('Choose a role first.'));

            return;
        }

        $this->runGuarded(fn () => $change->handle($this->actor(), $target, $role), __('Role updated.'));
    }

    public function confirmStatusChange(string $adminId): void
    {
        $this->authorize('staff.suspend');
        $this->targetId = AdminUser::query()->findOrFail($adminId)->id;
        $this->reset('reason');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'admin-status');
    }

    public function applyStatusChange(SetAdminStatus $status): void
    {
        $this->authorize('staff.suspend');
        $this->validate(['reason' => 'required|string|min:10|max:500']);
        $target = AdminUser::query()->findOrFail($this->targetId);

        $this->runGuarded(
            fn () => $target->isActive() && ! $target->isLocked()
                ? $status->suspend($this->actor(), $target, $this->reason)
                : $status->reactivate($this->actor(), $target, $this->reason),
            __('Account updated.'),
        );

        $this->dispatch('close-modal', name: 'admin-status');
        $this->reset('targetId', 'reason');
    }

    public function render(): View
    {
        return view('livewire.admin.staff.index');
    }

    private function runGuarded(callable $action, string $success): void
    {
        try {
            $action();
        } catch (GuardrailViolation $violation) {
            $this->dispatch('toast', type: 'error', message: $violation->getMessage());

            return;
        }

        unset($this->admins);
        $this->dispatch('toast', type: 'success', message: $success);
    }

    private function actor(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
