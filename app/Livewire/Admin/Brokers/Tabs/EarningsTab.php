<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers\Tabs;

use App\Enums\ReferralStatus;
use App\Livewire\Admin\Brokers\Concerns\IsBureauTab;
use App\Models\BrokerReferral;
use App\ValueObjects\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 bureau "Earnings" (§11A B.10, B.12): the bureau's commission ledger — referral and managed
 * conversions by profile code, base, rate, commission, status, and the payout run that paid it —
 * with totals per status. `brokers.view`.
 */
final class EarningsTab extends Component
{
    use IsBureauTab, WithPagination;

    #[Url(as: 'ledger', except: '')]
    public string $status = '';

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $broker = $this->broker();
        $totals = BrokerReferral::query()->where('broker_id', $broker->id)
            ->selectRaw('status, count(*) as n, coalesce(sum(commission_paise), 0) as paise')->groupBy('status')->get()
            ->mapWithKeys(fn (BrokerReferral $row): array => [$row->status->value => ['n' => (int) $row->getAttribute('n'), 'amount' => Money::paise((int) $row->getAttribute('paise'))]]);

        $rows = BrokerReferral::query()->where('broker_id', $broker->id)
            ->when(ReferralStatus::tryFrom($this->status), fn ($query, ReferralStatus $status) => $query->where('status', $status))
            ->with(['profile' => fn ($profile) => $profile->withTrashed()->select(['id', 'code']), 'order:id,code', 'payoutItem.run:id,code'])
            ->latest()->orderByDesc('id')->paginate(25);

        return view('livewire.admin.brokers.tabs.earnings-tab', [
            'rows' => $rows,
            'totals' => $totals,
            'statuses' => ReferralStatus::cases(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
