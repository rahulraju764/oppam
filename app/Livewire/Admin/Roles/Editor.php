<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Roles;

use App\Actions\Admin\Roles\UpdateRolePermissions;
use App\Domain\Admin\StaffGuardrails;
use App\Exceptions\Admin\GuardrailViolation;
use App\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Roles & permissions editor (A01, PRD §8.4). Anyone with roles.view can read the matrix;
 * saving needs roles.edit and passes the "can't grant what you don't hold" guardrail in
 * UpdateRolePermissions. The super_admin role is shown read-only.
 */
#[Layout('layouts::admin', ['title' => 'Roles & permissions'])]
final class Editor extends Component
{
    #[Url(except: '')]
    public string $role = '';

    /** @var list<string> */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('roles.view');
        $this->role = $this->roles()->contains('name', $this->role) ? $this->role : (string) $this->roles()->first()?->name;
        $this->loadSelection();
    }

    public function updatedRole(): void
    {
        abort_unless($this->roles()->contains('name', $this->role), 404);
        $this->loadSelection();
        $this->resetErrorBag();
    }

    /** @return Collection<int, Role> */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->where('guard_name', 'admin')->orderBy('name')->get();
    }

    /** @return array<string, list<string>> section => permission keys */
    #[Computed]
    public function groups(): array
    {
        /** @var array<string, list<string>> */
        return config('admin-permissions.permissions');
    }

    public function isFixed(): bool
    {
        return $this->role === StaffGuardrails::SUPER_ADMIN;
    }

    public function save(UpdateRolePermissions $update): void
    {
        $this->authorize('roles.edit');

        try {
            $update->handle($this->actor(), $this->role, $this->selected);
        } catch (GuardrailViolation $violation) {
            $this->dispatch('toast', type: 'error', message: $violation->getMessage());
            $this->loadSelection();

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Permissions saved.'));
    }

    public function render(): View
    {
        return view('livewire.admin.roles.editor');
    }

    private function loadSelection(): void
    {
        $role = $this->roles()->firstWhere('name', $this->role);
        $this->selected = $role?->permissions()->pluck('name')->values()->all() ?? [];
    }

    private function actor(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
