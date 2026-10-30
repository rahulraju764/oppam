<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\Signals\ResolveFraudSignal;
use App\Actions\Admin\Brokers\Signals\VoidAllReferrals;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerFraudSignal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A07 fraud & quality signals of one bureau (§11A B.12; P7.8c), on its Overview: each open signal
 * with what was counted (codes and counts only), when it was first and last seen; `brokers.edit`
 * resolves one with a note; `brokers.payout` may void all of the bureau's commissions ("broker
 * referring themselves → suspend, void all" — suspending is the Overview's own button). The
 * bureau code is #[Locked]; every action is authorized again in its Action.
 */
final class SignalsPanel extends Component
{
    #[Locked]
    public string $code = '';

    public string $target = '';

    public string $reason = '';

    public function mount(string $code): void
    {
        $this->authorize('brokers.view');
        $this->code = Broker::query()->where('code', strtoupper($code))->firstOrFail()->code;
    }

    public function confirm(string $modal, string $signalId = ''): void
    {
        $this->resetErrorBag();
        $this->reset('reason');
        $this->target = $signalId;
        $this->dispatch('open-modal', name: $modal);
    }

    public function resolve(ResolveFraudSignal $action): void
    {
        $this->resetErrorBag();

        try {
            $action->handle($this->admin(), $this->broker(), $this->target, $this->reason);
        } catch (BrokerStateConflict|ModelNotFoundException) {
            $this->dispatch('toast', type: 'error', message: __('This signal is no longer open.'));

            return;
        }

        $this->reset('reason', 'target');
        $this->dispatch('close-modal', name: 'signal-resolve');
        $this->dispatch('toast', type: 'success', message: __('Signal resolved. A payout hold, if any, stays until you release it.'));
    }

    public function voidAll(VoidAllReferrals $action): void
    {
        $this->resetErrorBag();
        $counts = $action->handle($this->admin(), $this->broker(), $this->reason);

        $this->reset('reason');
        $this->dispatch('close-modal', name: 'signal-void-all');
        $this->dispatch('toast', type: 'success', message: __('Voided :n commissions (:paid already paid — taken back in the next payout run).', [
            'n' => $counts['pending'] + $counts['earned'] + $counts['paid'], 'paid' => $counts['paid'],
        ]));
    }

    public function render(): View
    {
        /** @var Collection<int, BrokerFraudSignal> $signals */
        $signals = BrokerFraudSignal::query()->open()->where('broker_id', $this->broker()->id)->orderBy('detected_at')->get();

        return view('livewire.admin.brokers.signals-panel', [
            'signals' => $signals,
            'payoutsHeld' => $this->broker()->payouts_held_at !== null,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function broker(): Broker
    {
        return Broker::query()->where('code', $this->code)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
