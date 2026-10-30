<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers\Tabs;

use App\Enums\ReferralStage;
use App\Livewire\Admin\Brokers\Concerns\IsBureauTab;
use App\Queries\Broker\BureauReferrals;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 bureau "Referrals" (§11A B.12): the bureau's referred members (proved mobiles), by stage —
 * the same rows and stages the Owner sees (BureauReferrals), with the profile code linking into
 * A03. `brokers.view`.
 */
final class ReferralsTab extends Component
{
    use IsBureauTab, WithPagination;

    public string $stage = '';

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function updatedStage(): void
    {
        $this->resetPage();
    }

    public function render(BureauReferrals $referrals): View
    {
        $broker = $this->broker();

        return view('livewire.admin.brokers.tabs.referrals-tab', [
            'rows' => $referrals->page($broker->id, ReferralStage::tryFrom($this->stage)),
            'stats' => $referrals->stats($broker->id),
            'stages' => collect(ReferralStage::cases())->mapWithKeys(fn (ReferralStage $s): array => [$s->value => $s->label()])->all(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
