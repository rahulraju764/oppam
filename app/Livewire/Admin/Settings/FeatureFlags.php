<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Actions\Admin\Settings\ToggleFeatureFlag;
use App\Enums\Flag;
use App\Models\AdminUser;
use App\Support\Facades\Feature;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A15 feature flags (P8.4): every flag with what it does and its state. A switch opens a reason
 * dialog; the server decides the new state (the opposite of the current one) — the browser only
 * names the flag. Flags for features not built yet (push, boost) are shown, never togglable.
 */
#[Layout('layouts::admin', ['title' => 'Feature flags'])]
final class FeatureFlags extends Component
{
    #[Locked]
    public ?string $pending = null;

    #[Locked]
    public bool $pendingState = false;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function promptToggle(string $flag): void
    {
        $this->authorize('settings.edit');
        $found = $this->flag($flag);

        $this->resetValidation();
        $this->pending = $found->value;
        $this->pendingState = ! Feature::active($found);
        $this->reason = '';
        $this->dispatch('open-modal', name: 'flag-toggle');
    }

    public function confirmToggle(ToggleFeatureFlag $toggle): void
    {
        $found = $this->flag((string) $this->pending);
        $toggle->handle($this->admin(), $found, $this->pendingState, $this->reason);

        $this->pending = null;
        $this->dispatch('close-modal', name: 'flag-toggle');
        $this->dispatch('toast', type: 'success', message: $this->pendingState
            ? __(':flag switched on.', ['flag' => $found->label()])
            : __(':flag switched off.', ['flag' => $found->label()]));
    }

    public function render(): View
    {
        return view('livewire.admin.settings.feature-flags', [
            'flags' => Flag::cases(),
            'canEdit' => Gate::forUser($this->admin())->allows('settings.edit'),
            'pendingFlag' => Flag::tryFrom((string) $this->pending),
        ]);
    }

    private function flag(string $value): Flag
    {
        $flag = Flag::tryFrom($value);
        abort_if($flag === null || ! $flag->isBuilt(), 404);

        return $flag;
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
