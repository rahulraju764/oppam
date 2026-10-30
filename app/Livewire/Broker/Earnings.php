<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Enums\ReferralStatus;
use App\Enums\SettingKey;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerReferral;
use App\Models\User;
use App\Support\Facades\Settings;
use App\ValueObjects\Money;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/earnings (§11A B.10 "Visibility: Owner only (Earnings page + live commission earned
 * toast)", B.11, R-M13-24 — `earnings.view`): the bureau's commission — waiting for a first plan,
 * earned and not yet paid, paid, reversed — its rate, the ledger by profile code only (R-M13-7) and
 * every payout with its statement. The bureau is the caller's (BureauGate), never from the browser.
 * Refreshes live on `commission.earned` and `payout.paid` (broker-owner channel).
 */
final class Earnings extends Component
{
    use WithPagination;

    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::EarningsView);
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $staff = app(BureauGate::class)->staff($this->user());

        return $staff === null ? [] : [
            'echo-private:broker-owner.'.$staff->broker_id.',.commission.earned' => '$refresh',
            'echo-private:broker-owner.'.$staff->broker_id.',.payout.paid' => '$refresh',
        ];
    }

    public function render(BureauGate $gate): View
    {
        $staff = $gate->authorize($this->user(), BureauPermission::EarningsView);
        $broker = $staff->broker;
        $brokerId = $staff->broker_id;

        $totals = BrokerReferral::query()->where('broker_id', $brokerId)
            ->selectRaw('status, count(*) as n, coalesce(sum(commission_paise), 0) as paise')->groupBy('status')->get()
            ->mapWithKeys(fn (BrokerReferral $row): array => [$row->status->value => ['n' => (int) $row->getAttribute('n'), 'amount' => Money::paise((int) $row->getAttribute('paise'))]]);

        return view('livewire.broker.earnings', [
            'totals' => $totals,
            'pendingCount' => $totals[ReferralStatus::Pending->value]['n'] ?? 0,
            'unpaid' => $totals[ReferralStatus::Earned->value]['amount'] ?? Money::paise(0),
            'paid' => $totals[ReferralStatus::Paid->value]['amount'] ?? Money::paise(0),
            'rate' => rtrim(rtrim(number_format(((int) $broker?->commission_bps) / 100, 2, '.', ''), '0'), '.'),
            'minimum' => Money::paise(Settings::int(SettingKey::BrokerPayoutMinimumPaise)),
            'held' => $broker?->payouts_held_at !== null,
            'payouts' => BrokerPayoutItem::query()->where('broker_id', $brokerId)
                ->whereIn('status', [PayoutItemStatus::Paid, PayoutItemStatus::CarriedForward])
                ->whereHas('run', fn ($run) => $run->where('status', '!=', PayoutRunStatus::Draft))
                ->with('run:id,code,period_start,period_end')->latest()->limit(24)->get(),
            'ledger' => BrokerReferral::query()->where('broker_id', $brokerId)
                ->whereIn('status', [ReferralStatus::Earned, ReferralStatus::Paid, ReferralStatus::Void])
                ->with(['profile' => fn ($profile) => $profile->withTrashed()->select(['id', 'code'])])
                ->latest('earned_at')->orderByDesc('id')->paginate(15),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::broker', ['seo' => SeoData::private('Earnings | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
