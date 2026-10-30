<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\Payouts\CreatePayoutRun;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AdminUser;
use App\Models\BrokerPayoutRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 payout runs (§11A B.10, B.12, `brokers.payout`): every run with its period, status, lines
 * and total; "Create run" (CreatePayoutRun) for a period — last month by default — with an override
 * reason only when a paid run already covers part of it (B.17). The new DRAFT run opens as the preview.
 */
#[Layout('layouts::admin', ['title' => 'Payout runs'])]
final class PayoutsIndex extends Component
{
    use WithPagination;

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $overrideReason = '';

    public bool $needsOverride = false;

    public function mount(): void
    {
        $this->authorize('brokers.payout');

        $lastMonth = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfMonth()->subMonth();
        $this->periodStart = $lastMonth->toDateString();
        $this->periodEnd = $lastMonth->endOfMonth()->toDateString();
    }

    public function create(CreatePayoutRun $create): mixed
    {
        $this->resetErrorBag();

        try {
            $run = $create->handle($this->admin(), $this->periodStart, $this->periodEnd, $this->needsOverride ? $this->overrideReason : null);
        } catch (PayoutRefused $refused) {
            $this->needsOverride = $this->needsOverride || $refused->overridable;
            $this->addError('periodEnd', $refused->getMessage());

            return null;
        }

        return $this->redirectRoute('admin.brokers.payouts.show', ['run' => $run->code], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.brokers.payouts-index', [
            'runs' => BrokerPayoutRun::query()
                ->withCount('items')
                ->withSum(['items as net_total_paise' => fn ($q) => $q->where('net_paise', '>', 0)], 'net_paise')
                ->latest()->orderByDesc('id')->paginate(20),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
