<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\ReferralStage;
use App\Models\User;
use App\Queries\Broker\BureauReferrals;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/referrals (B.11 "own referrals only", `referrals.view`): the bureau's referred members
 * by profile code, join date and stage — never names, photos, contact or activity (R-M13-7). The
 * commission column is the Owner's only (B.10 visibility, `earnings.view`). The broker is the
 * caller's (BureauGate), never taken from the browser (R-M13-6). Refreshes on
 * `commission.earned` for Owners.
 */
final class Referrals extends Component
{
    use WithPagination;

    #[Url(as: 'stage')]
    public string $stage = '';

    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::ReferralsView);
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $gate = app(BureauGate::class);
        $staff = $gate->staff($this->user());

        return $staff !== null && $gate->allows($this->user(), BureauPermission::EarningsView)
            ? ['echo-private:broker-owner.'.$staff->broker_id.',.commission.earned' => '$refresh']
            : [];
    }

    public function updatedStage(): void
    {
        $this->resetPage();
    }

    public function render(BureauGate $gate, BureauReferrals $referrals): View
    {
        $user = $this->user();
        $staff = $gate->authorize($user, BureauPermission::ReferralsView);

        return view('livewire.broker.referrals', [
            'referrals' => $referrals->page($staff->broker_id, ReferralStage::tryFrom($this->stage)),
            'showCommission' => $gate->allows($user, BureauPermission::EarningsView),
            'stages' => ReferralStage::options(),
            'code' => $staff->broker?->code,
        ])->layout('layouts::broker', ['seo' => SeoData::private('Referrals | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
