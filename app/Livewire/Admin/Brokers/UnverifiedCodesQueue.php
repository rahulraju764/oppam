<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\ClearReferralCode;
use App\Actions\Admin\Brokers\LinkReferralCode;
use App\Domain\Broker\BrokerCodeLookup;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Queries\Admin\UnverifiedReferralCodes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 "Unverified codes" (PRD §11A B.12, `brokers.edit`): members whose referral code matched no
 * active bureau, with fuzzy suggestions (Levenshtein ≤ 2 over active codes). Selecting a row
 * (`?profile=` code) opens the decision: link it to a bureau (a suggestion or a typed code) or
 * clear it — both with a reason, both audited Actions; never automatic.
 */
#[Layout('layouts::admin', ['title' => 'Unverified referral codes'])]
final class UnverifiedCodesQueue extends Component
{
    use WithPagination;

    #[Url(as: 'profile', except: '')]
    public string $selected = '';

    public string $brokerCode = '';

    public string $reason = '';

    public function mount(): void
    {
        Gate::authorize('brokers.edit');
    }

    public function select(string $code): void
    {
        $this->selected = $code;
        $this->brokerCode = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->select('');
    }

    public function link(LinkReferralCode $link, UnverifiedReferralCodes $codes): void
    {
        $profile = $this->selectedProfile($codes) ?? abort(404);
        $brokerCode = BrokerCodeLookup::normalise($this->brokerCode) ?? '';

        $link->handle($this->admin(), $profile, $brokerCode, $this->reason);

        $this->done(__('Profile :code linked to :broker.', ['code' => $profile->code, 'broker' => $brokerCode]));
    }

    public function clear(ClearReferralCode $clear, UnverifiedReferralCodes $codes): void
    {
        $profile = $this->selectedProfile($codes) ?? abort(404);

        $clear->handle($this->admin(), $profile, $this->reason);

        $this->done(__('Code cleared from profile :code.', ['code' => $profile->code]));
    }

    public function render(UnverifiedReferralCodes $codes, BrokerCodeLookup $lookup): View
    {
        $profile = $this->selectedProfile($codes);
        $rows = $codes->page();
        $pool = $lookup->activePool();

        return view('livewire.admin.brokers.unverified-codes-queue', [
            'rows' => $rows,
            'suggestionsByRow' => collect($rows->items())->mapWithKeys(fn (Profile $row): array => [$row->code => $lookup->suggestions((string) $row->broker_code_raw, 3, $pool)]),
            'profile' => $profile,
            'suggestions' => $profile !== null ? $lookup->suggestions((string) $profile->broker_code_raw, 5, $pool) : collect(),
            'total' => $rows->total(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function done(string $message): void
    {
        $this->select('');
        $this->dispatch('toast', type: 'success', message: $message);
    }

    private function selectedProfile(UnverifiedReferralCodes $codes): ?Profile
    {
        return $this->selected === '' ? null : $codes->find($this->selected);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
