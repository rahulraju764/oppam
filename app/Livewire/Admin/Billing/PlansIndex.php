<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Actions\Admin\Billing\ChangePlanAvailability;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** A06 plan list: what each plan costs and how many run it; open the editor, deactivate, delete. */
#[Layout('layouts::admin', ['title' => 'Plans'])]
final class PlansIndex extends Component
{
    #[Locked]
    public ?string $selected = null;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    public function confirm(string $code, string $modal): void
    {
        $this->authorize('billing.plans.edit');
        $this->selected = Plan::query()->where('code', $code)->firstOrFail()->code->value;
        $this->reset('reason');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: $modal);
    }

    public function deactivate(ChangePlanAvailability $change): void
    {
        $this->change(fn (Plan $plan) => $change->setActive($this->admin(), $plan, false, $this->reason), 'plan-deactivate', __('Plan deactivated.'));
    }

    public function activate(ChangePlanAvailability $change): void
    {
        $this->change(fn (Plan $plan) => $change->setActive($this->admin(), $plan, true, $this->reason), 'plan-activate', __('Plan activated.'));
    }

    public function delete(ChangePlanAvailability $change): void
    {
        $this->change(fn (Plan $plan) => $change->delete($this->admin(), $plan, $this->reason), 'plan-delete', __('Plan deleted.'));
    }

    public function render(): View
    {
        return view('livewire.admin.billing.plans-index', [
            'plans' => Plan::query()->with(['prices' => fn ($q) => $q->orderBy('duration_months')])
                ->withCount(['subscriptions as running_count' => fn ($q) => $q->where('status', 'ACTIVE')->where('ends_at', '>', now())])
                ->orderBy('sort_order')->get(),
        ]);
    }

    private function change(callable $action, string $modal, string $done): void
    {
        $plan = Plan::query()->where('code', (string) $this->selected)->firstOrFail();

        try {
            $action($plan);
        } catch (BillingRefused $refused) {
            $this->dispatch('close-modal', name: $modal);
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: $modal);
        $this->dispatch('toast', type: 'success', title: $done);
        $this->reset('selected', 'reason');
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
