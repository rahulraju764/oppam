<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Actions\Admin\Settings\ToggleFeatureFlag;
use App\Enums\Flag;
use App\Models\AdminUser;
use App\Support\Facades\Feature;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Feature flags management (PRD §11 A15).
 * Allows toggling platform capabilities on/off with audit trail.
 */
#[Layout('layouts::admin', ['title' => 'Feature Flags'])]
final class FeatureFlags extends Component
{
    public bool $showConfirmModal = false;

    #[Locked]
    public ?string $selectedFlag = null;

    public string $selectedFlagLabel = '';

    public bool $targetState = false;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function promptToggle(string $flagValue, bool $target): void
    {
        $flag = Flag::tryFrom($flagValue);
        if ($flag === null) {
            return;
        }

        $this->selectedFlag = $flag->value;
        $this->selectedFlagLabel = $flag->name;
        $this->targetState = $target;
        $this->reason = '';
        $this->resetErrorBag();
        $this->showConfirmModal = true;
    }

    public function cancelToggle(): void
    {
        $this->showConfirmModal = false;
        $this->reset('selectedFlag', 'selectedFlagLabel', 'targetState', 'reason');
        $this->resetErrorBag();
    }

    public function confirmToggle(ToggleFeatureFlag $action): void
    {
        $this->authorize('settings.edit');

        if ($this->selectedFlag === null) {
            return;
        }

        $flag = Flag::from($this->selectedFlag);

        $this->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $action->handle($this->admin(), $flag, $this->targetState, $this->reason);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $stateLabel = $this->targetState ? __('enabled') : __('disabled');
        $this->showConfirmModal = false;
        session()->flash('status', __("Feature ':flag' has been :state.", ['flag' => $flag->value, 'state' => $stateLabel]));
    }

    public function render(): View
    {
        $flags = array_map(function (Flag $flag) {
            return [
                'enum' => $flag,
                'key' => $flag->value,
                'name' => $flag->name,
                'description' => $flag->description(),
                'isActive' => Feature::active($flag),
            ];
        }, Flag::cases());

        return view('livewire.admin.settings.feature-flags', [
            'flags' => $flags,
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
