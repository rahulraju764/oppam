<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Actions\Admin\Billing\ManageRefund;
use App\Enums\RefundStatus;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\Refund;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** A06 refunds: waiting first; a second admin approves or rejects, the requester may withdraw. */
#[Layout('layouts::admin', ['title' => 'Refunds'])]
final class RefundsIndex extends Component
{
    use WithPagination;

    #[Url(except: 'REQUESTED')]
    public string $status = 'REQUESTED';

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

    public function confirm(string $code, string $modal): void
    {
        $this->authorize('billing.refund');
        $this->selected = $this->find($code)->code;
        $this->reset('note');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: $modal);
    }

    public function approve(ManageRefund $refunds): void
    {
        $this->act(fn (Refund $r) => $refunds->approve($this->admin(), $r, trim($this->note) !== '' ? trim($this->note) : null), 'refund-approve', __('Approved and sent to the gateway.'));
    }

    public function reject(ManageRefund $refunds): void
    {
        $this->act(fn (Refund $r) => $refunds->reject($this->admin(), $r, $this->note), 'refund-reject', __('Refund rejected.'));
    }

    public function withdraw(ManageRefund $refunds, string $code): void
    {
        $this->selected = $this->find($code)->code;
        $this->act(fn (Refund $r) => $refunds->withdraw($this->admin(), $r), null, __('Refund withdrawn.'));
    }

    public function render(): View
    {
        return view('livewire.admin.billing.refunds-index', [
            'refunds' => Refund::query()
                ->when(RefundStatus::tryFrom($this->status), fn ($q, RefundStatus $status) => $q->where('status', $status))
                ->with(['order:id,code', 'profile:id,code', 'requestedBy:id,name', 'decidedBy:id,name', 'creditNote:id,refund_id,number'])
                ->latest()->orderByDesc('id')->paginate(25),
            'statuses' => collect(RefundStatus::cases())->mapWithKeys(fn (RefundStatus $s): array => [$s->value => $s->label()])->all(),
            'adminId' => $this->admin()->id,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function act(callable $action, ?string $modal, string $done): void
    {
        $this->resetErrorBag();

        try {
            $refund = $action($this->find((string) $this->selected));
        } catch (BillingRefused $refused) {
            if ($modal !== null) {
                $this->dispatch('close-modal', name: $modal);
            }
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        if ($modal !== null) {
            $this->dispatch('close-modal', name: $modal);
        }
        $failed = $refund->status === RefundStatus::Failed;
        $this->dispatch('toast', type: $failed ? 'danger' : 'success', title: $failed ? __('The gateway refused or couldn’t be reached. Nothing was sent; see Reconciliation.') : $done);
        $this->reset('selected', 'note');
    }

    private function find(string $code): Refund
    {
        return Refund::query()->where('code', $code)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
