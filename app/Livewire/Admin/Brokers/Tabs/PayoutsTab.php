<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers\Tabs;

use App\Livewire\Admin\Brokers\Concerns\IsBureauTab;
use App\Models\BrokerPayoutItem;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 bureau "Payouts" (§11A B.10, B.12): this bureau's lines in every payout run — gross,
 * clawback, carried, TDS, net, status, UTR — newest first, with the statement of each paid line
 * (statements need `brokers.payout`). `brokers.view`.
 */
final class PayoutsTab extends Component
{
    use IsBureauTab, WithPagination;

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function render(): View
    {
        return view('livewire.admin.brokers.tabs.payouts-tab', [
            'items' => BrokerPayoutItem::query()->where('broker_id', $this->broker()->id)
                ->with('run:id,code,period_start,period_end,status')->latest()->orderByDesc('id')->paginate(25),
            'broker' => $this->broker(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
