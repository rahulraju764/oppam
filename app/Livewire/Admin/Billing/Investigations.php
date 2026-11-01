<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Actions\Admin\Billing\ResolveInvestigation;
use App\Enums\InvestigationStatus;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\BillingInvestigation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** A06 "investigate" rows from the daily reconciliation and from refund failures; resolved with a note. */
#[Layout('layouts::admin', ['title' => 'Reconciliation'])]
final class Investigations extends Component
{
    use WithPagination;

    #[Url(except: 'OPEN')]
    public string $status = 'OPEN';

    #[Locked]
    public ?string $selected = null;

    public string $note = '';

    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function confirm(string $id): void
    {
        $this->authorize('billing.refund');
        $this->selected = $this->find($id)->id;
        $this->reset('note');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'investigation-resolve');
    }

    public function resolve(ResolveInvestigation $resolve): void
    {
        $this->resetErrorBag();
        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        try {
            $resolve->handle($admin, $this->find((string) $this->selected), $this->note);
        } catch (BillingRefused $refused) {
            $this->dispatch('close-modal', name: 'investigation-resolve');
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: 'investigation-resolve');
        $this->dispatch('toast', type: 'success', title: __('Resolved.'));
        $this->reset('selected', 'note');
    }

    public function render(): View
    {
        return view('livewire.admin.billing.investigations', [
            'rows' => BillingInvestigation::query()
                ->where('status', InvestigationStatus::tryFrom($this->status) ?? InvestigationStatus::Open)
                ->with(['order:id,code', 'refund:id,code', 'resolvedBy:id,name'])
                ->latest()->orderByDesc('id')->paginate(25),
            'statuses' => collect(InvestigationStatus::cases())->mapWithKeys(fn (InvestigationStatus $s): array => [$s->value => ucfirst(strtolower($s->value))])->all(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function find(string $id): BillingInvestigation
    {
        return preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? BillingInvestigation::query()->findOrFail(strtolower($id)) : abort(404);
    }
}
