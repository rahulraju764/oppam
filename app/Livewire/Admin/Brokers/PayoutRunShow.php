<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\Payouts\ApprovePayoutRun;
use App\Actions\Admin\Brokers\Payouts\DiscardPayoutRun;
use App\Actions\Admin\Brokers\Payouts\MarkPayoutPaid;
use App\Actions\Admin\Brokers\Payouts\RollPayoutLineForward;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AdminUser;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A07 payout run (§11A B.10, B.12, `brokers.payout`): the preview of a DRAFT run (every bureau's
 * line: earned, clawback, carried, TDS, net, and whether it pays or rolls forward), approve —
 * a second, different approver when a line is above the threshold (R-A07-4) — discard a draft,
 * then per line: mark paid with the UTR, or roll it forward; statements of paid lines. The run is
 * found by its code (#[Locked]); a line by the bureau code inside this run only.
 */
#[Layout('layouts::admin', ['title' => 'Payout run'])]
final class PayoutRunShow extends Component
{
    #[Locked]
    public string $code = '';

    /** The bureau whose line the "mark paid" / "roll forward" dialog is for (validated in the Action). */
    public string $line = '';

    public string $utr = '';

    public string $reason = '';

    public function mount(string $run): void
    {
        $this->authorize('brokers.payout');
        $this->code = strtoupper($run);
        $this->run();
    }

    public function openLine(string $brokerCode, string $dialog): void
    {
        $this->line = $brokerCode;
        $this->reset('utr', 'reason');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'payout-'.($dialog === 'roll' ? 'roll' : 'paid'));
    }

    public function approve(ApprovePayoutRun $approve): void
    {
        $this->attempt(function () use ($approve): void {
            $run = $approve->handle($this->admin(), $this->run());
            $this->dispatch('toast', type: 'success', message: match (true) {
                $run->status === PayoutRunStatus::Paid => __('Approved. Every line rolled forward, so the run is complete.'),
                $run->status === PayoutRunStatus::Approved => __('Run approved. Record each bank transfer with its UTR.'),
                default => __('First approval recorded. A different admin must give the second approval.'),
            });
        });
    }

    public function markPaid(MarkPayoutPaid $mark): void
    {
        $this->attempt(function () use ($mark): void {
            $item = $mark->handle($this->admin(), $this->run(), $this->line, $this->utr);
            $this->closeDialog('payout-paid', __('Payout to :code recorded (:amount).', ['code' => $this->line, 'amount' => $item->net()->formatWithSymbol()]));
        });
    }

    public function rollForward(RollPayoutLineForward $roll): void
    {
        $this->attempt(function () use ($roll): void {
            $roll->handle($this->admin(), $this->run(), $this->line, $this->reason);
            $this->closeDialog('payout-roll', __('The balance of :code rolls forward to the next run.', ['code' => $this->line]));
        });
    }

    public function discard(DiscardPayoutRun $discard): mixed
    {
        $failed = true;
        $this->attempt(function () use ($discard, &$failed): void {
            $discard->handle($this->admin(), $this->run(), $this->reason);
            $failed = false;
        });

        return $failed ? null : $this->redirectRoute('admin.brokers.payouts', navigate: true);
    }

    public function render(): View
    {
        $run = $this->run()->load(['createdBy:id,name', 'approvedBy:id,name', 'secondApprovedBy:id,name']);
        $items = BrokerPayoutItem::query()->where('run_id', $run->id)
            ->with('broker:id,code,name,bureau_name,status,kyc_status,payouts_held_at')
            ->orderByDesc('net_paise')->get();

        return view('livewire.admin.brokers.payout-run-show', [
            'run' => $run,
            'items' => $items,
            'needsSecond' => $run->status === PayoutRunStatus::Draft && $run->needsSecondApprover(),
            'payable' => $items->where('status', PayoutItemStatus::Pending),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->title($run->code.' · '.__('Payout run'));
    }

    private function attempt(Closure $step): void
    {
        $this->resetErrorBag();

        try {
            $step();
        } catch (PayoutRefused $refused) {
            $this->dispatch('toast', type: 'error', message: $refused->getMessage());
        }
    }

    private function closeDialog(string $name, string $message): void
    {
        $this->reset('line', 'utr', 'reason');
        $this->dispatch('close-modal', name: $name);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    private function run(): BrokerPayoutRun
    {
        return BrokerPayoutRun::query()->where('code', $this->code)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
